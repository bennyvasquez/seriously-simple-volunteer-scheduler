<?php

namespace App\Calendar;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The person search on the calendar panels (put someone on a shift, add a regular). Finds active people by name. Each match says whether they're cleared for regular shifts, so the picker can say "Not cleared yet". A coordinator or admin also matches on email; anyone else (an area lead) matches by name only. A person the viewer may see matches on any part of their name, and an email or full name is shown only for people the viewer may see (spec section 13). Anyone else matches only when the search is the start of their short name ("Robin E."), so the search can't be used to work out a surname the viewer isn't shown.
 */
final class PersonSearch
{
    public const MIN_LENGTH = 2;

    /** How many name matches are looked at before the ones the viewer can't see are filtered down to short-name matches. */
    private const CANDIDATES = 50;

    private const LIMIT = 8;

    /** Whether the viewer may search by email: coordinators and admins only, however many areas someone leads. */
    public static function byEmail(User $viewer): bool
    {
        return $viewer->isCoordinator();
    }

    /** The name to show a viewer for a person: the full name only when the viewer may see them. */
    public static function displayName(User $viewer, User $person): string
    {
        return $viewer->canSee($person) ? $person->name : $person->shortName();
    }

    /**
     * @param  iterable<int>  $excludeIds  People to leave out, such as those already on the shift.
     * @return array{query: string, tooShort: bool, matches: Collection<int, User>} Each match has `show_email` and `display_name` set.
     */
    public static function find(User $viewer, string $query, bool $byEmail, iterable $excludeIds = [], bool $enabled = true): array
    {
        $query = trim($query);

        if ($query === '' || mb_strlen($query) < self::MIN_LENGTH || ! $enabled) {
            return ['query' => $query, 'tooShort' => $query !== '' && mb_strlen($query) < self::MIN_LENGTH, 'matches' => collect()];
        }

        // A trailing period is ignored, so "Robin E." finds the same people as "Robin E".
        $like = '%'.addcslashes(rtrim($query, '.'), '%_\\').'%';
        $candidates = User::query()->whereNull('deactivated_at')
            ->whereNotIn('id', collect($excludeIds)->all())
            ->where(fn ($q) => $q->where('name', 'like', $like)->when($byEmail, fn ($q) => $q->orWhere('email', 'like', $like)))
            ->orderBy('name')->limit(self::CANDIDATES)->get(['id', 'name', 'email', 'cleared_at']);

        $visibleIds = $viewer->visiblePeople()->whereIn('users.id', $candidates->modelKeys())->pluck('users.id')->all();
        $needle = self::normalize($query);

        $matches = $candidates
            ->filter(fn (User $match) => in_array($match->id, $visibleIds, true) || str_starts_with(self::normalize($match->shortName()), $needle))
            ->take(self::LIMIT)->values()
            ->each(function (User $match) use ($visibleIds) {
                $match->setAttribute('show_email', in_array($match->id, $visibleIds, true));
                $match->setAttribute('display_name', in_array($match->id, $visibleIds, true) ? $match->name : $match->shortName());
            });

        return ['query' => $query, 'tooShort' => false, 'matches' => $matches];
    }

    /** Lower case, with a trailing period and surrounding spaces dropped, for comparing a search with a short name. */
    private static function normalize(string $text): string
    {
        return mb_strtolower(rtrim(trim($text), '. '));
    }
}
