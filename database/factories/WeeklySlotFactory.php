<?php

namespace Database\Factories;

use App\Enums\SlotFrequency;
use App\Models\Area;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeeklySlot>
 */
class WeeklySlotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'area_id' => Area::factory(),
            'weekday' => 4, // Thursday
            'start_time' => '15:30',
            'end_time' => '18:30',
            'people_needed' => 1,
            'shadow_spots' => 0,
            'frequency' => SlotFrequency::Weekly,
            'anchor_date' => null,
            'valid_from' => '2026-01-01',
            'valid_until' => null,
        ];
    }

    public function everyOtherWeek(string $anchorDate): static
    {
        return $this->state(fn () => [
            'frequency' => SlotFrequency::EveryOtherWeek,
            'anchor_date' => $anchorDate,
            'weekday' => CarbonImmutable::parse($anchorDate)->dayOfWeek,
        ]);
    }
}
