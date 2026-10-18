<?php

namespace App\Actions\Scheduling;

use App\Enums\SlotFrequency;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\User;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Creates a weekly slot and generates its shifts right away, so the calendar shows them without waiting for the nightly run. Times are local wall-clock times. For every other week, the first date is the anchor, so it has to fall on the slot's weekday.
 *
 * Attribute keys: weekday (0 is Sunday), start_time, end_time, headcount, shadow_spots, frequency, valid_from, valid_until, title, is_rotation.
 */
class CreateWeeklySlot
{
    public function __construct(private GenerateShifts $generator) {}

    public function handle(User $actor, Area $area, array $attributes): WeeklySlot
    {
        Gate::forUser($actor)->authorize('manageSchedule', $area);

        $organization = Organization::current();
        $frequency = $attributes['frequency'] instanceof SlotFrequency ? $attributes['frequency'] : SlotFrequency::from($attributes['frequency']);
        $validFrom = CarbonImmutable::parse($attributes['valid_from'])->toDateString();
        $validUntil = ($attributes['valid_until'] ?? null) === null ? null : CarbonImmutable::parse($attributes['valid_until'])->toDateString();
        $title = trim((string) ($attributes['title'] ?? ''));
        $weekday = (int) $attributes['weekday'];

        if ($validFrom < $organization->today()->toDateString()) {
            throw ValidationException::withMessages(['valid_from' => 'Pick a date from today on.']);
        }

        if ($validUntil !== null && $validUntil < $validFrom) {
            throw ValidationException::withMessages(['valid_until' => 'The last date has to be on or after the first date.']);
        }

        if ($frequency === SlotFrequency::EveryOtherWeek && CarbonImmutable::parse($validFrom, 'UTC')->dayOfWeek !== $weekday) {
            throw ValidationException::withMessages(['valid_from' => 'For every other week, the first date has to fall on the day the slot is for.']);
        }

        if (CarbonImmutable::parse($attributes['start_time'])->format('H:i') === CarbonImmutable::parse($attributes['end_time'])->format('H:i')) {
            throw ValidationException::withMessages(['end_time' => 'A shift has to end at a different time than it starts.']);
        }

        if ((int) $attributes['headcount'] < 1) {
            throw ValidationException::withMessages(['headcount' => 'At least 1 person is needed.']);
        }

        $slot = new WeeklySlot([
            'area_id' => $area->id,
            'weekday' => $weekday,
            'start_time' => $attributes['start_time'],
            'end_time' => $attributes['end_time'],
            'headcount' => (int) $attributes['headcount'],
            'shadow_spots' => (int) $attributes['shadow_spots'],
            'frequency' => $frequency,
            'anchor_date' => $frequency === SlotFrequency::EveryOtherWeek ? $validFrom : null,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'title' => $title === '' ? null : $title,
            'is_rotation' => (bool) ($attributes['is_rotation'] ?? false),
        ]);

        return DB::transaction(function () use ($actor, $organization, $slot) {
            $changes = ChangeLogEntry::changesOf($slot);
            $slot->save();

            $created = $this->generator->forSlot($organization, $slot);

            ChangeLogEntry::record($actor, 'slot.created', $slot, ['area_id' => $slot->area_id, 'created_shifts' => $created], $changes);

            return $slot;
        });
    }
}
