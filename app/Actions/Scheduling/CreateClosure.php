<?php

namespace App\Actions\Scheduling;

use App\Models\Area;
use App\Models\AreaClosure;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\ShiftCancelledNotification;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Closes one or more areas for a date range, both days included (spec section 8.5). A range that started before today is stored from today; one that ended before today is refused. Adds one closure per area and cancels every shift in those areas and dates that hasn't started and isn't already cancelled; the shifts stay, with the reason and the closure set, and the nightly run skips the dates from then on. A closure that overlaps an earlier one is allowed, but shifts the earlier one already cancelled are left as they are, so nobody is emailed twice for the same shift.
 *
 * Each closure gets a closure.created change log entry listing the shifts it cancelled, and each of those shifts gets its own shift.cancelled entry (via closure.created, with the closure's id), all in one transaction. A slot edit never brings these shifts back, because it only undoes cancellations made by a slot edit. After the response, each active person on the cancelled shifts gets one email listing all of their cancelled shifts.
 */
class CreateClosure
{
    /**
     * @param  list<int>  $areaIds
     * @return Collection<int, AreaClosure> One closure per area, in the order given.
     */
    public function handle(User $actor, array $areaIds, string $startsOn, string $endsOn, string $reason): Collection
    {
        $areaIds = array_values(array_unique(array_map('intval', $areaIds)));

        if ($areaIds === []) {
            throw new DomainException('Pick at least one area.');
        }

        $areas = Area::query()->whereKey($areaIds)->get()->sortBy(fn (Area $area) => array_search($area->id, $areaIds, true))->values();

        if ($areas->count() !== count($areaIds)) {
            throw new DomainException('One of those areas no longer exists.');
        }

        foreach ($areas as $area) {
            Gate::forUser($actor)->authorize('close', $area);
        }

        $first = self::date($startsOn);
        $last = self::date($endsOn);

        if ($first === null || $last === null) {
            throw new DomainException('Pick the first and last day closed.');
        }

        if ($last->lessThan($first)) {
            throw new DomainException('The last day closed has to be on or after the first day.');
        }

        $today = CarbonImmutable::parse(Organization::current()->today()->toDateString(), 'UTC');

        if ($last->lessThan($today)) {
            throw new DomainException('Pick days from today on.');
        }

        // Days already past can't be closed, so a closure that started earlier is stored from today.
        $first = $first->max($today);

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('Say why it\'s closed, so volunteers know.');
        }

        if (mb_strlen($reason) > 200) {
            throw new DomainException('Keep it to 200 characters or fewer.');
        }

        [$closures, $cancelledByPerson] = DB::transaction(function () use ($actor, $areas, $first, $last, $reason) {
            $closures = new EloquentCollection;
            /** @var array<int, array{person: User, shifts: list<Shift>}> $cancelledByPerson */
            $cancelledByPerson = [];

            // Lock the areas in id order before anything else, the same lock GenerateShifts takes before it makes a shift, so a shift can't be made on a day this closes after the sweep below has looked.
            Area::query()->whereKey($areas->modelKeys())->orderBy('id')->lockForUpdate()->get();

            foreach ($areas as $area) {
                $closure = new AreaClosure([
                    'area_id' => $area->id,
                    'starts_on' => $first->toDateString(),
                    'ends_on' => $last->toDateString(),
                    'reason' => $reason,
                ]);
                $closureChanges = ChangeLogEntry::changesOf($closure);
                $closure->save();

                // Locked so nobody is put on a shift between reading its people and cancelling it.
                $shifts = Shift::query()
                    ->where('area_id', $area->id)
                    ->whereBetween('local_date', [$first->toDateString(), $last->toDateString()])
                    ->whereNull('cancelled_at')
                    ->where('starts_at', '>', now())
                    ->orderBy('starts_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $cancelled = [];

                foreach ($shifts as $shift) {
                    $shift->cancelled_at = now();
                    $shift->cancel_reason = $reason;
                    $shift->area_closure_id = $closure->id;
                    $changes = ChangeLogEntry::changesOf($shift);
                    $shift->save();

                    $people = $shift->assignments()->with('user')->get()->pluck('user')->filter(fn (User $user) => $user->isActive())->values();

                    ChangeLogEntry::record($actor, 'shift.cancelled', $shift, [
                        'area_id' => $shift->area_id,
                        'people' => $people->pluck('id')->all(),
                        'via' => 'closure.created',
                        'area_closure_id' => $closure->id,
                    ], $changes);

                    $shift->setRelation('area', $area);
                    foreach ($people as $person) {
                        $cancelledByPerson[$person->id] ??= ['person' => $person, 'shifts' => []];
                        $cancelledByPerson[$person->id]['shifts'][] = $shift;
                    }
                    $cancelled[] = $shift->id;
                }

                ChangeLogEntry::record($actor, 'closure.created', $closure, [
                    'area_id' => $area->id,
                    'cancelled_shifts' => $cancelled,
                ], $closureChanges);

                $closure->setRelation('area', $area);
                $closures->push($closure);
            }

            return [$closures, $cancelledByPerson];
        });

        defer(function () use ($cancelledByPerson, $reason) {
            foreach ($cancelledByPerson as $entry) {
                $shifts = collect($entry['shifts'])->sortBy(fn (Shift $shift) => $shift->starts_at->getTimestamp())->values();
                $entry['person']->notify(new ShiftCancelledNotification($shifts, $reason));
            }
        });

        return $closures;
    }

    private static function date(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

        return $date !== false && $date->toDateString() === $value ? $date : null;
    }
}
