<?php

namespace App\Calendar;

use App\Models\Area;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The person search on the calendar panels (put someone on a shift, add a regular). Finds active people by name. Each match says whether they're cleared for regular shifts, so the picker can say "Not cleared yet". Someone who can manage every area also matches on email; anyone else (an area lead) matches by name only, and an email or full name is shown only for people the viewer may see (spec section 13); others show as a short name.
 */
final class PersonSearch
{
    public const MIN_LENGTH = 2;

    /** Whether the viewer can manage every area, and so may search by email. */
    public static function byEmail(User $viewer): bool
    {
        return Area::query()->get()->every(fn (Area $area) => $viewer->canManageArea($area));
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

        $like = '%'.addcslashes($query, '%_\\').'%';
        $matches = User::query()->whereNull('deactivated_at')
            ->whereNotIn('id', collect($excludeIds)->all())
            ->where(fn ($q) => $q->where('name', 'like', $like)->when($byEmail, fn ($q) => $q->orWhere('email', 'like', $like)))
            ->orderBy('name')->limit(8)->get(['id', 'name', 'email', 'cleared_at'])
            ->each(function (User $match) use ($viewer) {
                $match->setAttribute('show_email', $viewer->canSee($match));
                $match->setAttribute('display_name', self::displayName($viewer, $match));
            });

        return ['query' => $query, 'tooShort' => false, 'matches' => $matches];
    }
}
