<?php

namespace App\Actions\Scheduling;

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
 * A coordinator or area lead puts someone on one shift, as a regular for that date or as a shadow. Refuses a shift that is cancelled or has started, a person who is already on it, turned off or on another active shift at an overlapping time, and a shift with no open place (or shadow spot). The refusals are written to be shown on screen. Logged with the shift as the subject and about the person, with the new assignment's values; they're emailed after the response.
 */
class PutOnShift
{
    public function handle(User $actor, Shift $shift, User $person, AssignmentType $type): Assignment
    {
        Gate::forUser($actor)->authorize('assign', $shift);

        $assignment = DB::transaction(function () use ($actor, $shift, $person, $type) {
            // Lock the shift so two people filling the last place at once can't both succeed.
            $shift = Shift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();

            if ($shift->cancelled_at !== null) {
                throw new DomainException('This shift is cancelled.');
            }

            if ($shift->starts_at->lessThanOrEqualTo(now())) {
                throw new DomainException('This shift has already started.');
            }

            if (! $person->isActive()) {
                throw new DomainException($person->firstName().' has been turned off and can\'t be put on a shift.');
            }

            if ($shift->assignments()->where('user_id', $person->id)->exists()) {
                throw new DomainException($person->firstName().' is already on this shift.');
            }

            if ($type === AssignmentType::Regular && $shift->openPlaces() === 0) {
                throw new DomainException('This shift is full.');
            }

            if ($type === AssignmentType::Shadow && $shift->assignments()->where('type', AssignmentType::Shadow)->count() >= $shift->shadow_spots) {
                throw new DomainException('There\'s no shadow spot left on this shift.');
            }

            $overlapping = self::overlappingShift($person, $shift);

            if ($overlapping !== null) {
                throw new DomainException($person->firstName().' is already on '.$overlapping->area->name.', '.$overlapping->whenText().'.');
            }

            $assignment = new Assignment(['shift_id' => $shift->id, 'user_id' => $person->id, 'type' => $type]);
            $changes = ChangeLogEntry::changesOf($assignment);
            $assignment->save();

            // About the person, with the shift as the subject, so a shift's history is found with forSubject($shift).
            ChangeLogEntry::record($actor, 'assignment.added', $shift, ['assignment_id' => $assignment->id, 'type' => $type->value], $changes, about: $person);

            return $assignment;
        });

        // After the transaction commits and after the response, so a mail failure never shows an error for a change that was saved.
        defer(fn () => $person->notify(new PutOnShiftNotification($shift->loadMissing('area'))));

        return $assignment;
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
