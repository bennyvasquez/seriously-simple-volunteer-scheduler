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
 * A coordinator or area lead puts someone on one shift, as a regular for that date or as a shadow. Refuses a shift that is cancelled or has started, a person who is already on it or turned off, and a shift with no open place (or shadow spot). The refusals are written to be shown on screen. Logged about the person; they're emailed after the response.
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
                throw new DomainException(self::firstName($person).' has been turned off and can\'t be put on a shift.');
            }

            if ($shift->assignments()->where('user_id', $person->id)->exists()) {
                throw new DomainException(self::firstName($person).' is already on this shift.');
            }

            if ($type === AssignmentType::Regular && $shift->openPlaces() === 0) {
                throw new DomainException('This shift is full. Raise the number of people needed first.');
            }

            if ($type === AssignmentType::Shadow && $shift->assignments()->where('type', AssignmentType::Shadow)->count() >= $shift->shadow_spots) {
                throw new DomainException('There\'s no shadow spot left on this shift.');
            }

            $assignment = new Assignment(['shift_id' => $shift->id, 'user_id' => $person->id, 'type' => $type]);
            $assignment->save();

            ChangeLogEntry::record($actor, 'assignment.added', $assignment, ['shift_id' => $shift->id, 'area_id' => $shift->area_id, 'type' => $type->value], about: $person);

            return $assignment;
        });

        // After the transaction commits and after the response, so a mail failure never shows an error for a change that was saved.
        defer(fn () => $person->notify(new PutOnShiftNotification($shift->loadMissing('area'))));

        return $assignment;
    }

    private static function firstName(User $person): string
    {
        return explode(' ', trim($person->name))[0];
    }
}
