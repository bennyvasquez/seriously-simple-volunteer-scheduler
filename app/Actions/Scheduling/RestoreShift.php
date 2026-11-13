<?php

namespace App\Actions\Scheduling;

use App\Models\Area;
use App\Models\AreaClosure;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\ShiftRestoredNotification;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Brings back a shift that was cancelled by hand, with everyone who was on it. Refuses a shift that isn't cancelled or has started, one a closure or a weekly slot change cancelled (those come back when the closure or the slot is changed), and a date an area closure covers now. Anyone on it who is now on another active shift at an overlapping time is removed from this one, and the status says so. The people still on it who can sign in are emailed after the response. The refusals are written to be shown on screen. restoreLocked() is the shared step: closure changes call it with the area and shift already locked and send any email themselves.
 */
class RestoreShift
{
    public const HAND = 'hand';

    public const CLOSURE = 'closure';

    public const SLOT = 'slot';

    /** How the shift was cancelled: `closure` when it carries a closure, `slot` when its latest shift.cancelled entry came from a weekly slot change (never judged by the reason text), otherwise `hand`. */
    public static function cancelledBy(Shift $shift): string
    {
        if ($shift->area_closure_id !== null) {
            return self::CLOSURE;
        }

        $entry = ChangeLogEntry::query()->forSubject($shift)->ofAction('shift.cancelled')->latest('id')->first();

        return ($entry?->details['via'] ?? null) === 'slot.updated' ? self::SLOT : self::HAND;
    }

    /**
     * Clears the cancellation, logs shift.restored with `$via`, and removes each person now on another active shift at an overlapping time. The caller holds the area and shift locks, has authorized the actor, checked the shift is cancelled and hasn't started, and sends any email.
     *
     * @return array{emailed: Collection<int, User>, removed: Collection<int, User>} The people still on the shift who can sign in, and the people removed from it.
     */
    public function restoreLocked(User $actor, Shift $lockedShift, array $via): array
    {
        $lockedShift->cancelled_at = null;
        $lockedShift->cancel_reason = null;
        $lockedShift->area_closure_id = null;
        $changes = ChangeLogEntry::changesOf($lockedShift);
        $lockedShift->save();

        ChangeLogEntry::record($actor, 'shift.restored', $lockedShift, ['area_id' => $lockedShift->area_id] + $via, $changes);

        // Read after the locks, so the people and their other shifts are the committed ones.
        $assignments = Assignment::query()->with('user')->where('shift_id', $lockedShift->id)->orderBy('id')->lockForUpdate()->get();
        $removed = collect();
        $staying = collect();

        foreach ($assignments as $assignment) {
            if (PutOnShift::overlappingShift($assignment->user, $lockedShift) !== null) {
                app(RemoveFromShift::class)->removeLocked($actor, $lockedShift, $assignment, ['via' => 'shift.restored']);
                $removed->push($assignment->user);
            } else {
                $staying->push($assignment->user);
            }
        }

        return ['emailed' => $staying->filter(fn (User $user) => $user->canSignIn())->values(), 'removed' => $removed];
    }

    /** @return array{emailed: int, removed: Collection<int, User>} */
    public function handle(User $actor, Shift $shift): array
    {
        Gate::forUser($actor)->authorize('restore', $shift);

        $result = DB::transaction(function () use ($actor, $shift) {
            Area::query()->whereKey($shift->area_id)->lockForUpdate()->firstOrFail();
            $locked = Shift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();

            if ($locked->cancelled_at === null) {
                throw new DomainException('This shift isn\'t cancelled.');
            }

            if ($locked->starts_at->lessThanOrEqualTo(now())) {
                throw new DomainException('This shift has already started.');
            }

            $by = self::cancelledBy($locked);

            if ($by === self::CLOSURE) {
                throw new DomainException('This shift was cancelled by a closure. Change the closure to bring it back.');
            }

            if ($by === self::SLOT) {
                throw new DomainException('The weekly slot doesn\'t run on this date any more. Change the slot to bring it back.');
            }

            $closure = AreaClosure::query()->where('area_id', $locked->area_id)
                ->where('starts_on', '<=', $locked->local_date->toDateString())->where('ends_on', '>=', $locked->local_date->toDateString())->first();

            if ($closure !== null) {
                throw new DomainException(Area::query()->find($locked->area_id)->name.' is closed that day: '.$closure->reason.'.');
            }

            $result = $this->restoreLocked($actor, $locked, []);
            $shift->setRawAttributes($locked->getAttributes(), true);

            return $result;
        });

        $shift->loadMissing('area');
        $emailed = $result['emailed'];
        defer(fn () => $emailed->each(fn (User $person) => $person->notify(new ShiftRestoredNotification($shift))));

        return ['emailed' => $emailed->count(), 'removed' => $result['removed']];
    }
}
