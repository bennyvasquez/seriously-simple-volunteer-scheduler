<?php

namespace App\Actions\Scheduling;

use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\User;
use App\Models\WeeklySlot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Creates a weekly slot and generates its shifts right away, so the calendar shows them without waiting for the nightly run. Times are local wall-clock times. For every other week, the first date is the anchor, so it has to fall on the slot's weekday.
 *
 * Attribute keys: weekday (0 is Sunday), start_time, end_time, headcount, shadow_spots, frequency, valid_from, valid_until, title, is_rotation. SlotAttributes checks them.
 */
class CreateWeeklySlot
{
    public function __construct(private GenerateShifts $generator) {}

    public function handle(User $actor, Area $area, array $attributes): WeeklySlot
    {
        Gate::forUser($actor)->authorize('manageSchedule', $area);

        $organization = Organization::current();
        $slot = new WeeklySlot(['area_id' => $area->id] + SlotAttributes::validate($attributes, null));

        return DB::transaction(function () use ($actor, $organization, $slot) {
            // The area row first, as CreateClosure and the generator take it, so saving the slot (which reads the area for its foreign key) can't deadlock with a closure.
            Area::query()->whereKey($slot->area_id)->lockForUpdate()->first();

            $changes = ChangeLogEntry::changesOf($slot);
            $slot->save();

            $created = $this->generator->forSlot($organization, $slot);

            ChangeLogEntry::record($actor, 'slot.created', $slot, ['area_id' => $slot->area_id, 'created_shifts' => $created], $changes);

            return $slot;
        });
    }
}
