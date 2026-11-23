<?php

namespace App\Cover;

use App\Actions\Scheduling\PutOnShift;
use App\Enums\AssignmentType;
use App\Models\CoverRequest;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who can take a released shift, in one place (decision 4): anyone cleared for regular shifts who isn't on the shift and isn't on another active shift at an overlapping time. Skills are planned later and will join here.
 */
final class CoverEligibility
{
    public const NOT_CLEARED = 'You\'ll be able to take shifts once a coordinator clears you.';

    /**
     * Who gets a cover request email for this shift: people who can sign in, are cleared for regular shifts, have cover request emails on, aren't on the shift (as a regular or a shadow), aren't on another active shift that overlaps it (PutOnShift::overlappingShift(), one query per person, which is fine at a single organization's size), and didn't release a place on it themselves. Ordered by id.
     *
     * @return Collection<int, User>
     */
    public static function recipients(Shift $shift): Collection
    {
        return User::query()->people()
            ->whereNull('deactivated_at')
            ->whereNotNull('invited_at')
            ->whereNotNull('cleared_at')
            ->where('cover_request_emails', true)
            ->whereDoesntHave('assignments', fn (Builder $query) => $query->where('shift_id', $shift->id))
            // Someone who released it can take it back from Home, but asking them to cover their own shift would read oddly.
            ->whereNotIn('id', CoverRequest::query()->where('shift_id', $shift->id)->select('released_by'))
            ->orderBy('id')
            ->get()
            ->filter(fn (User $person) => PutOnShift::overlappingShift($person, $shift) === null)
            ->values();
    }

    /**
     * Why this person can't take the shift, written to be shown to them, or null when they can. Open places are checked separately, when they take it. Someone already on the shift hears that first, then someone who isn't cleared hears NOT_CLEARED. TakeShift passes the person it read again under the shift lock, so a move back to shadowing that commits first is seen.
     *
     * A screen checking many shifts for one person passes `$mine`: that person's active shifts, each with its area and its assignments for that person only. The answer is then worked out in memory with no queries, and reads the same as the queried one.
     *
     * @param  Collection<int, Shift>|null  $mine
     */
    public static function refusal(User $person, Shift $shift, ?Collection $mine = null): ?string
    {
        if ($shift->cancelled_at !== null) {
            return 'This shift was cancelled.';
        }

        if ($shift->starts_at->lessThanOrEqualTo(now())) {
            return 'This shift has already started.';
        }

        $type = $mine === null
            ? $shift->assignments()->where('user_id', $person->id)->first()?->type
            : $mine->firstWhere('id', $shift->id)?->assignments->firstWhere('user_id', $person->id)?->type;

        if ($type !== null) {
            return $type === AssignmentType::Shadow ? 'You\'re shadowing this shift.' : 'You\'re already on this shift.';
        }

        if (! $person->isCleared()) {
            return self::NOT_CLEARED;
        }

        return self::overlapRefusal($person, $shift, $mine);
    }

    /**
     * "You're already on {Area}, {when}." for another active shift the person is on at an overlapping time, or null. Shared by taking a shift and signing up for a shadow spot. `$mine` works the same way as in refusal().
     *
     * @param  Collection<int, Shift>|null  $mine
     */
    public static function overlapRefusal(User $person, Shift $shift, ?Collection $mine = null): ?string
    {
        $overlapping = $mine === null
            ? PutOnShift::overlappingShift($person, $shift)
            : $mine->where('id', '!=', $shift->id)->filter(fn (Shift $other) => $other->starts_at < $shift->ends_at && $other->ends_at > $shift->starts_at)->sortBy('starts_at')->first();

        return $overlapping === null ? null : 'You\'re already on '.$overlapping->area->name.', '.$overlapping->whenText().'.';
    }
}
