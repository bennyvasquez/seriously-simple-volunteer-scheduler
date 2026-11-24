<?php

namespace App\Actions\Scheduling;

use App\Cover\CoverEligibility;
use App\Cover\CoverFill;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\ShadowSignedUpLeadNotification;
use App\Notifications\ShadowSignedUpNotification;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Someone who isn't cleared yet signs up for a shadow spot (spec section 9, "Shadow this shift"). It locks the area, then the shift, and reads the person again with a shared lock (as TakeShift does), so the first of two people pressing it for the last spot gets it and the other hears NO_SPOT. A spot is offered only on a shift with at least one regular on it, so a trainee always has someone to learn from. Logged as `assignment.added` with `via` "shadow_signup", about the person, with the person as the actor. After the commit and the response the person gets "You're shadowing {Area} on {date}" and the area's active leads get "{First name} is shadowing".
 */
class TakeShadowSpot
{
    public const CLEARED = 'Shadow spots are for people who aren\'t cleared yet. You can take shifts instead.';

    public const NOBODY_TO_SHADOW = 'Nobody is on this shift to shadow yet.';

    public const NO_SPOT = 'There\'s no shadow spot left on this shift.';

    /** Returns the person's new shadow assignment on this shift. */
    public function handle(User $person, Shift $shift): Assignment
    {
        Gate::forUser($person)->authorize('take', $shift);

        [$assignment, $locked, $current, $regulars] = DB::transaction(function () use ($person, $shift) {
            Area::query()->whereKey($shift->area_id)->lockForUpdate()->firstOrFail();
            $locked = Shift::query()->with('area')->whereKey($shift->id)->lockForUpdate()->firstOrFail();

            // Read again after the shift lock, so a turn-off or a clearing that committed since the request loaded the person is seen. A shared lock, never a lock for update, because a user row is never locked for update after a shift row.
            $current = User::query()->whereKey($person->id)->sharedLock()->first();
            $refusal = $current === null ? TakeShift::TURNED_OFF : self::refusal($current, $locked);

            if ($refusal !== null) {
                throw new DomainException($refusal);
            }

            $assignment = new Assignment(['shift_id' => $locked->id, 'user_id' => $current->id, 'type' => AssignmentType::Shadow]);
            $changes = ChangeLogEntry::changesOf($assignment);
            $assignment->save();

            ChangeLogEntry::record($current, 'assignment.added', $locked, ['assignment_id' => $assignment->id, 'type' => AssignmentType::Shadow->value, 'via' => 'shadow_signup'], $changes, about: $current);

            $regulars = $locked->assignments()->with('user')->where('type', AssignmentType::Regular)->orderBy('id')->get()->map(fn (Assignment $regular) => $regular->user->shortName())->all();

            return [$assignment, $locked, $current, $regulars];
        });

        $leads = User::query()->people()->whereNull('deactivated_at')->whereKeyNot($current->id)
            ->whereHas('roles', fn ($roles) => $roles->where('role', Role::AreaLead->value)->where('area_id', $locked->area_id))
            ->orderBy('id')->get()->filter(fn (User $lead) => $lead->canSignIn());
        $sends = [[$current, new ShadowSignedUpNotification($locked, $regulars)]];

        foreach ($leads as $lead) {
            $sends[] = [$lead, new ShadowSignedUpLeadNotification($locked, $current->shortName(), $regulars)];
        }

        defer(CoverFill::sender($sends));

        return $assignment;
    }

    /**
     * Why this person can't sign up for a shadow spot on the shift, written to be shown to them, or null when they can. Queries the shift's assignments, so the shadow page and handle() (under its locks) read the same.
     */
    public static function refusal(User $person, Shift $shift): ?string
    {
        if ($shift->cancelled_at !== null) {
            return 'This shift was cancelled.';
        }

        if ($shift->starts_at->lessThanOrEqualTo(now())) {
            return 'This shift has already started.';
        }

        if (! $person->isActive()) {
            return TakeShift::TURNED_OFF;
        }

        $assignments = $shift->assignments()->get();
        $type = $assignments->firstWhere('user_id', $person->id)?->type;

        if ($type !== null) {
            return $type === AssignmentType::Shadow ? 'You\'re shadowing this shift.' : 'You\'re already on this shift.';
        }

        if ($person->isCleared()) {
            return self::CLEARED;
        }

        if (! $assignments->contains('type', AssignmentType::Regular)) {
            return self::NOBODY_TO_SHADOW;
        }

        if ($assignments->where('type', AssignmentType::Shadow)->count() >= $shift->shadow_spots) {
            return self::NO_SPOT;
        }

        return CoverEligibility::overlapRefusal($person, $shift);
    }
}
