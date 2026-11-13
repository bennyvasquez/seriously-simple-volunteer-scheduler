<?php

namespace App\Actions\Scheduling;

use App\Models\Area;
use App\Models\AreaClosure;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Models\WeeklySlot;
use App\Notifications\ShiftCancelledNotification;
use App\Notifications\ShiftRestoredNotification;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Changes a closure's days or reason (spec section 8.5), with the same checks and messages as adding one. A closure that has started keeps its first day, and its last day can move only while it's yesterday or later; ending it yesterday reopens today. Days that have passed are never changed: to fix them, remove the closure.
 *
 * In one transaction, locking the area, the closure and then every shift the change can touch (by start, then id): days it no longer covers get their shifts back (see releaseLocked()), newly covered days have their shifts cancelled (CreateClosure::cancelCovered()), and a new reason is copied onto the shifts it cancelled, without email. Shifts that have started are never changed. After the transaction commits, each of the area's weekly slots is generated again, so days that reopened get the shifts they never had; generation locks the slot and its regulars, which can't come after the shift locks held inside. After the response, each person gets at most one email: the shifts cancelled, the shifts back on, or both.
 */
class UpdateClosure
{
    /** The reason left on a shift that comes out from under a closure on a day its weekly slot no longer runs. */
    public const SLOT_CHANGED = 'The weekly slot changed';

    public const STARTED = 'This closure has already started, so only its last day can change. To fix days that have passed, remove it.';

    /**
     * What the last handle() did, for the status line: the shifts cancelled and brought back, how many people are emailed, and who was removed from a shift that came back because they're now on another shift at that time.
     *
     * @var array{cancelled: Collection<int, Shift>, restored: Collection<int, Shift>, emailed: int, removed: Collection<int, User>}
     */
    public array $outcome;

    public function __construct(
        private readonly CreateClosure $createClosure,
        private readonly RestoreShift $restoreShift,
        private readonly GenerateShifts $generateShifts,
    ) {
        $this->outcome = ['cancelled' => collect(), 'restored' => collect(), 'emailed' => 0, 'removed' => collect()];
    }

    public function handle(User $actor, AreaClosure $closure, string $startsOn, string $endsOn, string $reason): AreaClosure
    {
        Gate::forUser($actor)->authorize('close', $closure->area);

        [$first, $last, $reason] = CreateClosure::checkedValues($startsOn, $endsOn, $reason);
        $today = CarbonImmutable::parse(Organization::current()->today()->toDateString(), 'UTC');

        [$saved, $cancelledByPerson, $released, $reopened] = DB::transaction(function () use ($actor, $closure, $first, $last, $reason, $today) {
            // The area first, the lock CreateClosure and the generator take, then the closure itself, read again so the checks below use what is stored now.
            $area = Area::query()->whereKey($closure->area_id)->lockForUpdate()->firstOrFail();
            $locked = AreaClosure::query()->whereKey($closure->id)->lockForUpdate()->first();

            if ($locked === null) {
                throw new DomainException('This closure was removed.');
            }

            $oldFirst = $locked->starts_on;
            $oldLast = $locked->ends_on;

            if ($oldFirst->lessThan($today)) {
                $yesterday = $today->subDay();

                if (! $first->equalTo($oldFirst) || (! $last->equalTo($oldLast) && ($last->lessThan($yesterday) || $oldLast->lessThan($yesterday)))) {
                    throw new DomainException(self::STARTED);
                }
            } else {
                if ($last->lessThan($today)) {
                    throw new DomainException('Pick days from today on.');
                }

                // Days already past can't be closed, the same as adding a closure.
                $first = $first->max($today);
            }

            // Every shift this change can touch, locked in start order before any of them changes.
            $shifts = Shift::query()
                ->where('area_id', $area->id)
                ->whereBetween('local_date', [$oldFirst->min($first)->toDateString(), $oldLast->max($last)->toDateString()])
                ->where('starts_at', '>', now())
                ->orderBy('starts_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $locked->fill(['starts_on' => $first->toDateString(), 'ends_on' => $last->toDateString(), 'reason' => $reason]);
            // The date cast stores the new days with a midnight time; log plain dates, the same as the old values.
            $changes = ChangeLogEntry::changesOf($locked);
            foreach (array_intersect_key($changes, ['starts_on' => true, 'ends_on' => true]) as $key => $change) {
                $changes[$key] = array_map(fn (?string $value) => $value === null ? null : substr($value, 0, 10), $change);
            }

            if ($changes === []) {
                return [$locked->setRelation('area', $area), [], ['restoredByPerson' => [], 'restored' => [], 'removed' => collect()], false];
            }

            $locked->save();
            $locked->setRelation('area', $area);

            // Days it now covers: before the old first day and after the old last day.
            $added = [];

            if ($first->lessThan($oldFirst)) {
                $added[] = [$first->toDateString(), $last->min($oldFirst->subDay())->toDateString()];
            }

            if ($last->greaterThan($oldLast)) {
                $added[] = [$first->max($oldLast->addDay())->toDateString(), $last->toDateString()];
            }

            // The shifts cancelCovered() will cancel below, read from the locked set before anything changes.
            $cancelled = $shifts->filter(function (Shift $shift) use ($added) {
                $date = $shift->local_date->toDateString();

                return $shift->cancelled_at === null && collect($added)->contains(fn (array $range) => $range[0] <= $date && $date <= $range[1]);
            })->values();

            // Days it no longer covers.
            $leaving = $shifts->filter(fn (Shift $shift) => $shift->area_closure_id === $locked->id && $shift->cancelled_at !== null && ! $locked->covers($shift->local_date->toDateString()))->values();
            $released = $this->releaseLocked($actor, $locked, $leaving, 'closure.updated');

            // A new reason goes on the shifts it still cancels, without email.
            foreach ($shifts as $shift) {
                if ($shift->area_closure_id === $locked->id && $shift->cancelled_at !== null && $shift->cancel_reason !== $reason) {
                    $shift->cancel_reason = $reason;
                    $shiftChanges = ChangeLogEntry::changesOf($shift);
                    $shift->save();
                    ChangeLogEntry::record($actor, 'shift.updated', $shift, ['area_id' => $shift->area_id, 'via' => 'closure.updated', 'area_closure_id' => $locked->id], $shiftChanges);
                }
            }

            $cancelledByPerson = [];

            foreach ($added as [$from, $to]) {
                foreach ($this->createClosure->cancelCovered($actor, $locked, $from, $to, 'closure.updated') as $personId => $entry) {
                    $cancelledByPerson[$personId] ??= ['person' => $entry['person'], 'shifts' => []];
                    array_push($cancelledByPerson[$personId]['shifts'], ...$entry['shifts']);
                }
            }

            ChangeLogEntry::record($actor, 'closure.updated', $locked, [
                'area_id' => $area->id,
                'starts_on' => $first->toDateString(),
                'ends_on' => $last->toDateString(),
                'cancelled_shifts' => $cancelled->modelKeys(),
                'restored_shifts' => array_map(fn (Shift $shift) => $shift->id, $released['restored']),
            ], $changes);

            $this->outcome['cancelled'] = $cancelled;

            // Any day it covered before and doesn't now may need shifts made.
            $reopened = $first->greaterThan($oldFirst) || $last->lessThan($oldLast);

            return [$locked, $cancelledByPerson, $released, $reopened];
        });

        if ($reopened) {
            $this->generate($actor, $saved->area, 'closure.updated');
        }

        $this->outcome['restored'] = collect($released['restored']);
        $this->outcome['removed'] = $released['removed'];
        $this->outcome['emailed'] = self::sendEmails($cancelledByPerson, $released['restoredByPerson'], $saved->reason);

        return $saved;
    }

    /**
     * What happens to a cancelled shift when its closure no longer covers its day: another closure of the area that covers the day takes it over, a weekly slot that no longer runs on the day leaves it cancelled (SLOT_CHANGED), and otherwise it comes back. The closure edit page uses this for its preview, and releaseLocked() under the locks.
     */
    public static function fate(Shift $shift, AreaClosure $closure): AreaClosure|string|null
    {
        $date = $shift->local_date->toDateString();
        $other = AreaClosure::query()
            ->where('area_id', $closure->area_id)
            ->whereKeyNot($closure->id)
            ->where('starts_on', '<=', $date)
            ->where('ends_on', '>=', $date)
            ->orderBy('starts_on')
            ->orderBy('id')
            ->first();

        if ($other !== null) {
            return $other;
        }

        $slot = $shift->weekly_slot_id === null ? null : WeeklySlot::query()->find($shift->weekly_slot_id);

        return $slot !== null && ! $slot->occursOn($date) ? self::SLOT_CHANGED : null;
    }

    /**
     * Releases the closure's cancelled shifts that haven't started, for days it no longer covers: each is handed to another closure of the area that covers its day (its id and reason, logged shift.updated), left cancelled with SLOT_CHANGED when its weekly slot no longer runs that day (logged shift.updated, nobody emailed), or brought back with RestoreShift::restoreLocked(). The caller holds the area, closure and shift locks and sends the email. RemoveClosure uses this too.
     *
     * @param  Collection<int, Shift>  $lockedShifts
     * @return array{restoredByPerson: array<int, array{person: User, shifts: list<Shift>}>, restored: list<Shift>, removed: Collection<int, User>}
     */
    public function releaseLocked(User $actor, AreaClosure $closure, Collection $lockedShifts, string $via): array
    {
        $restoredByPerson = [];
        $restored = [];
        $removed = collect();

        foreach ($lockedShifts as $shift) {
            $fate = self::fate($shift, $closure);

            if ($fate === null) {
                $result = $this->restoreShift->restoreLocked($actor, $shift, ['via' => $via, 'area_closure_id' => $closure->id]);
                $shift->setRelation('area', $closure->area);
                $restored[] = $shift;
                $removed = $removed->merge($result['removed']);

                foreach ($result['emailed'] as $person) {
                    $restoredByPerson[$person->id] ??= ['person' => $person, 'shifts' => []];
                    $restoredByPerson[$person->id]['shifts'][] = $shift;
                }

                continue;
            }

            $shift->area_closure_id = $fate instanceof AreaClosure ? $fate->id : null;
            $shift->cancel_reason = $fate instanceof AreaClosure ? $fate->reason : $fate;
            $changes = ChangeLogEntry::changesOf($shift);
            $shift->save();

            ChangeLogEntry::record($actor, 'shift.updated', $shift, ['area_id' => $shift->area_id, 'via' => $via, 'area_closure_id' => $closure->id], $changes);
        }

        return ['restoredByPerson' => $restoredByPerson, 'restored' => $restored, 'removed' => $removed->unique('id')->values()];
    }

    /** Generates the area's weekly slots again after a closure stops covering days, so the days that reopened get their shifts. Runs after the closure's transaction commits. Logs what it made as shifts.generated, with the closure change in `via`. */
    public function generate(User $actor, Area $area, string $via): int
    {
        $organization = Organization::current();
        $created = 0;

        foreach (WeeklySlot::query()->where('area_id', $area->id)->orderBy('id')->get() as $slot) {
            $created += $this->generateShifts->forSlot($organization, $slot);
        }

        if ($created > 0) {
            $first = CarbonImmutable::parse($organization->today()->toDateString(), 'UTC');
            ChangeLogEntry::record($actor, 'shifts.generated', $organization, [
                'created' => $created,
                'from' => $first->toDateString(),
                'to' => $first->addDays($organization->weeks_ahead * 7 - 1)->toDateString(),
                'area_id' => $area->id,
                'via' => $via,
            ]);
        }

        return $created;
    }

    /**
     * Sends each person one email after the response: their cancelled shifts with the reason, their shifts that are back on, or both in one email.
     *
     * @param  array<int, array{person: User, shifts: list<Shift>}>  $cancelledByPerson
     * @param  array<int, array{person: User, shifts: list<Shift>}>  $restoredByPerson
     * @return int How many people are emailed.
     */
    public static function sendEmails(array $cancelledByPerson, array $restoredByPerson, string $reason): int
    {
        $byStart = fn (array $shifts) => collect($shifts)->sortBy(fn (Shift $shift) => $shift->starts_at->getTimestamp())->values();
        $people = [];

        foreach ([$cancelledByPerson, $restoredByPerson] as $list) {
            foreach ($list as $id => $entry) {
                $people[$id] = $entry['person'];
            }
        }

        defer(function () use ($people, $cancelledByPerson, $restoredByPerson, $reason, $byStart) {
            foreach ($people as $id => $person) {
                $cancelled = $byStart($cancelledByPerson[$id]['shifts'] ?? []);
                $restored = $byStart($restoredByPerson[$id]['shifts'] ?? []);

                $person->notify($cancelled->isNotEmpty() ? new ShiftCancelledNotification($cancelled, $reason, $restored) : new ShiftRestoredNotification($restored));
            }
        });

        return count($people);
    }
}
