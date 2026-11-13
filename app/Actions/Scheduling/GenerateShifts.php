<?php

namespace App\Actions\Scheduling;

use App\Enums\AssignmentType;
use App\Models\Area;
use App\Models\AreaClosure;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Creates dated shifts from weekly slots for the organization's generation window (spec section 7).
 *
 * Existing shifts are never changed, so edits to a single date survive. Dates covered by a closure and shifts that have already started are skipped. A new shift gets the regulars who work that date, leaving out anyone whose account is turned off; both are read again under the area, slot and regular locks before each new shift is filled, so a turn-off or an ended regular that commits mid-run is seen. All of them go on, even when that is more than the people needed. Running it twice is harmless. A run that creates shifts is logged once, as the system.
 */
class GenerateShifts
{
    /**
     * @return int The number of shifts created.
     */
    public function handle(Organization $organization): int
    {
        // Work in plain calendar dates from the organization's today, never the UTC date.
        $firstDay = CarbonImmutable::parse($organization->today()->toDateString(), 'UTC');
        $days = $organization->weeks_ahead * 7;
        $lastDay = $firstDay->addDays($days - 1);

        $slots = WeeklySlot::query()
            ->where('valid_from', '<=', $lastDay->toDateString())
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', $firstDay->toDateString()))
            ->get();

        $created = 0;

        foreach ($slots as $slot) {
            $created += $this->forSlot($organization, $slot);
        }

        if ($created > 0) {
            ChangeLogEntry::record(null, 'shifts.generated', $organization, [
                'created' => $created,
                'from' => $firstDay->toDateString(),
                'to' => $lastDay->toDateString(),
            ]);
        }

        return $created;
    }

    /**
     * Generates one slot's shifts for the organization's window, from the slot as it is stored now. Logs nothing itself; the caller's change log entry covers it.
     *
     * @return int The number of shifts created.
     */
    public function forSlot(Organization $organization, WeeklySlot $slot): int
    {
        $firstDay = CarbonImmutable::parse($organization->today()->toDateString(), 'UTC');
        $days = $organization->weeks_ahead * 7;
        $lastDay = $firstDay->addDays($days - 1);

        // Read again, so a change saved since the caller loaded the slot is what gets generated. Its regulars are read under the locks for each new shift (see createShift()).
        $slot = $slot->fresh();

        if ($slot === null) {
            return 0;
        }

        $closures = AreaClosure::query()
            ->where('area_id', $slot->area_id)
            ->where('starts_on', '<=', $lastDay->toDateString())
            ->where('ends_on', '>=', $firstDay->toDateString())
            ->get();

        $created = 0;

        foreach ($slot->datesWithin($firstDay, $lastDay) as $date) {
            if ($this->isClosed($closures, $date)) {
                continue;
            }

            if ($this->createShift($organization, $slot, $date)) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * @param  Collection<int, AreaClosure>|null  $closures
     */
    private function isClosed(?Collection $closures, string $date): bool
    {
        return $closures !== null && $closures->contains(fn (AreaClosure $closure) => $closure->covers($date));
    }

    private function createShift(Organization $organization, WeeklySlot $slot, string $date): bool
    {
        [$startsAt, $endsAt] = $slot->timesOn($date, $organization->timezone);

        if ($startsAt->lessThanOrEqualTo(CarbonImmutable::now())) {
            return false;
        }

        return DB::transaction(function () use ($slot, $date, $startsAt, $endsAt) {
            // The same area lock CreateClosure takes, then a locking read of the closures, so a closure saved after the run read them still keeps this date empty.
            Area::query()->whereKey($slot->area_id)->lockForUpdate()->first();

            // Then the slot and its regulars, read again under the lock, before the shift is created: the area, slot, regulars, shifts order every scheduling action uses. The regulars loaded at the start of the run can be stale, for example when a turn-off and its release (ReleaseFutureSchedule) committed while the run waited. When a slot save calls this inside its own transaction, UpdateWeeklySlot has already locked the slot and its regulars before any shift and CreateWeeklySlot's new slot has none, so these reads take no new lock on a regular after a shift.
            WeeklySlot::query()->whereKey($slot->id)->lockForUpdate()->first();
            $regulars = SlotRegular::query()->where('weekly_slot_id', $slot->id)->orderBy('id')->lockForUpdate()->get();

            $closed = AreaClosure::query()
                ->where('area_id', $slot->area_id)
                ->where('starts_on', '<=', $date)
                ->where('ends_on', '>=', $date)
                ->lockForUpdate()
                ->first();

            if ($closed !== null) {
                return false;
            }

            $shift = Shift::query()->firstOrCreate(
                ['weekly_slot_id' => $slot->id, 'local_date' => $date],
                [
                    'area_id' => $slot->area_id,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'people_needed' => $slot->people_needed,
                    'shadow_spots' => $slot->shadow_spots,
                    'title' => $slot->title,
                ],
            );

            if (! $shift->wasRecentlyCreated) {
                return false;
            }

            // Regulars working this date whose accounts are still on, the earliest added first. Each person is read with a shared lock (the only lock a user row may take after a shift row), so a turn-off that committed is seen, and one still in progress waits for this run and its release then finds the new shift.
            $working = $regulars
                ->filter(fn (SlotRegular $regular) => $regular->activeOn($date))
                ->filter(fn (SlotRegular $regular) => User::query()->whereKey($regular->user_id)->sharedLock()->first()?->isActive() ?? false);

            foreach ($working as $regular) {
                $shift->assignments()->create([
                    'user_id' => $regular->user_id,
                    'type' => AssignmentType::Regular,
                ]);
            }

            return true;
        });
    }
}
