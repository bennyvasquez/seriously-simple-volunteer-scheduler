<?php

namespace App\Cover;

use App\Enums\AssignmentType;
use App\Models\Assignment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * How far someone has got with shadowing (spec section 9): a shadow shift counts once it has ended, unless it was cancelled or the person was marked a no-show. A shadow place someone left is gone, so it never counts.
 */
final class ShadowProgress
{
    /** The person's shadow shifts that count so far. */
    public static function done(User $person): int
    {
        return Assignment::query()
            ->where('user_id', $person->id)
            ->where('type', AssignmentType::Shadow)
            ->whereNull('no_show_at')
            ->whereHas('shift', fn ($shifts) => $shifts->active()->where('ends_at', '<=', now()))
            ->count();
    }

    /**
     * Every shadow place of theirs on a shift that has ended, newest first, with the shift and its area, so the person page can show done, no-show and cancelled ones.
     *
     * @return Collection<int, Assignment>
     */
    public static function history(User $person): Collection
    {
        return Assignment::query()
            ->with('shift.area')
            ->where('user_id', $person->id)
            ->where('type', AssignmentType::Shadow)
            ->whereHas('shift', fn ($shifts) => $shifts->where('ends_at', '<=', now()))
            ->join('shifts', 'shifts.id', '=', 'assignments.shift_id')
            ->orderByDesc('shifts.starts_at')
            ->orderByDesc('assignments.id')
            ->select('assignments.*')
            ->get();
    }

    /**
     * Active people who aren't cleared and have done at least the required number of shadow shifts, in one query (a count in a subquery, not a loop). Raising the required number never un-clears anyone, so this is only ever about people who aren't cleared.
     *
     * @return Builder<User>
     */
    public static function readyForReview(): Builder
    {
        $required = (int) Organization::current()->required_shadow_shifts;

        $done = Assignment::query()
            ->selectRaw('count(*)')
            ->whereColumn('assignments.user_id', 'users.id')
            ->where('assignments.type', AssignmentType::Shadow)
            ->whereNull('assignments.no_show_at')
            ->whereHas('shift', fn ($shifts) => $shifts->active()->where('ends_at', '<=', now()));

        return User::query()
            ->whereNull('deactivated_at')
            ->whereNull('cleared_at')
            ->where('is_removed_person', false)
            ->whereRaw('('.$done->toSql().') >= ?', [...$done->getBindings(), $required]);
    }
}
