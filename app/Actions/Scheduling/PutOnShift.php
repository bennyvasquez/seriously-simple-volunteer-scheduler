<?php

namespace App\Actions\Scheduling;

use App\Cover\CoverFill;
use App\Enums\AssignmentType;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\PutOnShiftNotification;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A coordinator or area lead puts someone on one shift, as a regular for that date or as a shadow. A regular place fills the shift's oldest open cover request through CoverFill::fillLocked(), so the ladder stops and the releaser and shadows hear who's covering; the leads aren't told, since a coordinator or lead did it. Someone shadowing the shift who is put on as a regular moves to a regular place on the same assignment through makeRegular() (#21), which counts as a new regular place for people needed and fills a cover request the same way. Refuses a shift that is cancelled or has started, a person who is already on it (except that move), turned off (checked again under the shift lock) or on another active shift at an overlapping time, and a shadow when the shadow spots are taken. A regular beyond the people the shift needs is allowed only when `$overConfirmed` is true; without it NeedsOverConfirmation is thrown with the date and the count. The refusals are written to be shown on screen. Logged with the shift as the subject and about the person, with the new assignment's values; they're emailed after the response.
 */
class PutOnShift
{
    public function handle(User $actor, Shift $shift, User $person, AssignmentType $type, bool $overConfirmed = false): Assignment
    {
        Gate::forUser($actor)->authorize('assign', $shift);

        [$assignment, $fill] = DB::transaction(function () use ($actor, $shift, $person, $type, $overConfirmed) {
            // Lock the shift so two people can't both be checked against the same count at once.
            $shift = Shift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();

            if ($shift->cancelled_at !== null) {
                throw new DomainException('This shift is cancelled.');
            }

            if ($shift->starts_at->lessThanOrEqualTo(now())) {
                throw new DomainException('This shift has already started.');
            }

            // Read again after the shift lock, so a turn-off that committed since the caller loaded the person is seen. A shared lock, never a lock for update, because a user row is never locked for update after a shift row; it also holds a turn-off back until this commits, and the release that follows it then finds this shift.
            $current = User::query()->whereKey($person->id)->sharedLock()->first();

            if ($current === null || ! $current->isActive()) {
                throw new DomainException($person->shortName().' has been turned off and can\'t be put on a shift.');
            }

            // Locked after the shift. A shadow put on as a regular moves to a regular place instead of being refused (#21).
            $existing = $shift->assignments()->where('user_id', $person->id)->lockForUpdate()->first();

            if ($existing !== null && ! ($existing->type === AssignmentType::Shadow && $type === AssignmentType::Regular)) {
                throw new DomainException($person->shortName().' is already on this shift.');
            }

            if ($type === AssignmentType::Regular && ! $overConfirmed && $shift->openPlaces() === 0) {
                throw new NeedsOverConfirmation([['date' => $shift->local_date->toDateString(), 'people' => $shift->filledCount() + 1, 'needed' => $shift->people_needed]]);
            }

            if ($type === AssignmentType::Shadow && $shift->assignments()->where('type', AssignmentType::Shadow)->count() >= $shift->shadow_spots) {
                throw new DomainException('There\'s no shadow spot left on this shift.');
            }

            $overlapping = self::overlappingShift($person, $shift);

            if ($overlapping !== null) {
                throw new DomainException($person->shortName().' is already on '.$overlapping->area->name.', '.$overlapping->whenText().'.');
            }

            $nobodyButShadowsBefore = $shift->filledCount() === 0;

            if ($existing !== null) {
                $assignment = self::makeRegular($actor, $shift, $existing, $person);
            } else {
                $assignment = new Assignment(['shift_id' => $shift->id, 'user_id' => $person->id, 'type' => $type]);
                $changes = ChangeLogEntry::changesOf($assignment);
                $assignment->save();

                // About the person, with the shift as the subject, so a shift's history is found with forSubject($shift).
                ChangeLogEntry::record($actor, 'assignment.added', $shift, ['assignment_id' => $assignment->id, 'type' => $type->value], $changes, about: $person);
            }

            // Locked after the shift, in CoverFill. A shadow doesn't cover a released place.
            $fill = $type === AssignmentType::Regular ? CoverFill::fillLocked($actor, $shift, $current, $nobodyButShadowsBefore) : null;

            return [$assignment, $fill];
        });

        // After the transaction commits and after the response, so a mail failure never shows an error for a change that was saved.
        defer(function () use ($person, $shift, $fill) {
            $person->notify(new PutOnShiftNotification($shift->loadMissing('area')));

            if ($fill !== null) {
                $fill();
            }
        });

        return $assignment;
    }

    /**
     * Turns someone's shadow place on a shift into a regular place on the same assignment (#21), freeing the shadow spot, and logs `assignment.type_changed` with the shift as the subject, about the person, with `type` changing from shadow to regular. `$details` adds to the entry's details (AddRegular passes `via` "take" for a self-claim). The caller holds the shift and assignment locks and fills any cover request itself. Used by handle() and AddRegular.
     *
     * @param  array<string, string>  $details
     */
    public static function makeRegular(?User $actor, Shift $lockedShift, Assignment $shadow, User $person, array $details = []): Assignment
    {
        $shadow->type = AssignmentType::Regular;
        $changes = ChangeLogEntry::changesOf($shadow);
        $shadow->save();

        ChangeLogEntry::record($actor, 'assignment.type_changed', $lockedShift, ['assignment_id' => $shadow->id] + $details, $changes, about: $person);

        return $shadow;
    }

    /** Another active shift the person is on (as a regular or a shadow) whose time overlaps this one, or null. Back-to-back shifts don't overlap. */
    public static function overlappingShift(User $person, Shift $shift): ?Shift
    {
        return Shift::query()->with('area')
            ->whereKeyNot($shift->id)
            ->whereNull('cancelled_at')
            ->where('starts_at', '<', $shift->ends_at)
            ->where('ends_at', '>', $shift->starts_at)
            ->whereHas('assignments', fn ($query) => $query->where('user_id', $person->id))
            ->orderBy('starts_at')
            ->first();
    }
}
