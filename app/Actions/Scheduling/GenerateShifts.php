<?php

namespace App\Actions\Scheduling;

use App\Enums\AssignmentType;
use App\Models\AreaClosure;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Creates dated shifts from weekly slots for the organization's generation window (spec section 7).
 *
 * Existing shifts are never changed, so edits to a single date survive. Dates covered by a closure and shifts that have already started are skipped. Running it twice is harmless.
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
            ->with('regulars')
            ->get();

        $closuresByArea = AreaClosure::query()
            ->where('starts_on', '<=', $lastDay->toDateString())
            ->where('ends_on', '>=', $firstDay->toDateString())
            ->get()
            ->groupBy('area_id');

        $created = 0;

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $firstDay->addDays($offset)->toDateString();

            foreach ($slots as $slot) {
                if (! $slot->occursOn($date) || $this->isClosed($closuresByArea->get($slot->area_id), $date)) {
                    continue;
                }

                if ($this->createShift($organization, $slot, $date)) {
                    $created++;
                }
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
            $shift = Shift::query()->firstOrCreate(
                ['weekly_slot_id' => $slot->id, 'local_date' => $date],
                [
                    'area_id' => $slot->area_id,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'headcount' => $slot->headcount,
                    'shadow_spots' => $slot->shadow_spots,
                ],
            );

            if (! $shift->wasRecentlyCreated) {
                return false;
            }

            foreach ($slot->regulars as $regular) {
                if ($regular->activeOn($date)) {
                    $shift->assignments()->create([
                        'user_id' => $regular->user_id,
                        'type' => AssignmentType::Regular,
                    ]);
                }
            }

            return true;
        });
    }
}
