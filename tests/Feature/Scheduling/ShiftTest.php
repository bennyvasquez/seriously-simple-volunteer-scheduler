<?php

namespace Tests\Feature\Scheduling;

use App\Models\Shift;
use App\Models\WeeklySlot;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftTest extends TestCase
{
    use RefreshDatabase;

    private function shiftAttributes(WeeklySlot $slot): array
    {
        return [
            'area_id' => $slot->area_id,
            'weekly_slot_id' => $slot->id,
            'local_date' => '2026-10-15',
            'starts_at' => '2026-10-15 19:30:00',
            'ends_at' => '2026-10-15 22:30:00',
            'people_needed' => 1,
            'shadow_spots' => 0,
        ];
    }

    public function test_a_slot_has_only_one_shift_per_date(): void
    {
        $slot = WeeklySlot::factory()->create();
        Shift::create($this->shiftAttributes($slot));

        $this->expectException(UniqueConstraintViolationException::class);

        Shift::create($this->shiftAttributes($slot));
    }

    public function test_shift_times_come_back_in_utc(): void
    {
        $shift = Shift::create($this->shiftAttributes(WeeklySlot::factory()->create()))->fresh();

        $this->assertSame('2026-10-15 19:30:00', $shift->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $shift->starts_at->getTimezone()->getName());
        $this->assertSame('2026-10-15', $shift->local_date->toDateString());
    }
}
