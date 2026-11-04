<?php

namespace App\Actions\Scheduling;

use App\Models\ChangeLogEntry;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\ShiftCancelledNotification;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Cancels one date (reason up to 200 characters). The shift stays (the nightly run would only recreate it) with cancelled_at and the reason set, and everyone on it is emailed after the response. Refuses a shift that has started or is already cancelled.
 */
class CancelShift
{
    /** Returns how many people were emailed. */
    public function handle(User $actor, Shift $shift, string $reason): int
    {
        Gate::forUser($actor)->authorize('cancel', $shift);

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('Say why it\'s cancelled, so the people on it know.');
        }

        if (mb_strlen($reason) > 200) {
            throw new DomainException('Keep it to 200 characters or fewer.');
        }

        $people = DB::transaction(function () use ($actor, $shift, $reason) {
            $fresh = Shift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();

            if ($fresh->cancelled_at !== null) {
                throw new DomainException('This shift is already cancelled.');
            }

            if ($fresh->starts_at->lessThanOrEqualTo(now())) {
                throw new DomainException('This shift has already started.');
            }

            $fresh->cancelled_at = now();
            $fresh->cancel_reason = $reason;
            $changes = ChangeLogEntry::changesOf($fresh);
            $fresh->save();

            $people = $fresh->assignments()->with('user')->get()->pluck('user')->filter(fn (User $user) => $user->canSignIn())->values();

            ChangeLogEntry::record($actor, 'shift.cancelled', $fresh, ['area_id' => $fresh->area_id, 'people' => $people->pluck('id')->all()], $changes);

            $shift->setRawAttributes($fresh->getAttributes(), true);

            return $people;
        });

        $shifts = collect([$shift->loadMissing('area')]);
        defer(fn () => $people->each(fn (User $person) => $person->notify(new ShiftCancelledNotification($shifts, $reason))));

        return $people->count();
    }
}
