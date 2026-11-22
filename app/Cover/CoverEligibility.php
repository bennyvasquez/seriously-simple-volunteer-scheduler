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
 * Who can take a released shift, in one place (decision 4). For now that's anyone who isn't on the shift and isn't on another active shift at an overlapping time. The clearance plan adds "cleared" and "has the skill" here.
 */
final class CoverEligibility
{
    /**
     * Who gets a cover request email for this shift: people who can sign in, have cover request emails on, aren't on the shift (as a regular or a shadow), aren't on another active shift that overlaps it (PutOnShift::overlappingShift(), one query per person, which is fine at a single organization's size), and didn't release a place on it themselves. Ordered by id.
     *
     * @return Collection<int, User>
     */
    public static function recipients(Shift $shift): Collection
    {
        return User::query()->people()
            ->whereNull('deactivated_at')
            ->whereNotNull('invited_at')
            ->where('cover_request_emails', true)
            ->whereDoesntHave('assignments', fn (Builder $query) => $query->where('shift_id', $shift->id))
            // Someone who released it can take it back from Home, but asking them to cover their own shift would read oddly.
            ->whereNotIn('id', CoverRequest::query()->where('shift_id', $shift->id)->select('released_by'))
            ->orderBy('id')
            ->get()
            ->filter(fn (User $person) => PutOnShift::overlappingShift($person, $shift) === null)
            ->values();
    }

    /** Why this person can't take the shift, written to be shown to them, or null when they can. Open places are checked separately, when they take it. */
    public static function refusal(User $person, Shift $shift): ?string
    {
        if ($shift->cancelled_at !== null) {
            return 'This shift was cancelled.';
        }

        if ($shift->starts_at->lessThanOrEqualTo(now())) {
            return 'This shift has already started.';
        }

        $type = $shift->assignments()->where('user_id', $person->id)->first()?->type;

        if ($type !== null) {
            return $type === AssignmentType::Shadow ? 'You\'re shadowing this shift.' : 'You\'re already on this shift.';
        }

        $overlapping = PutOnShift::overlappingShift($person, $shift);

        if ($overlapping !== null) {
            return 'You\'re already on '.$overlapping->area->name.', '.$overlapping->whenText().'.';
        }

        return null;
    }
}
