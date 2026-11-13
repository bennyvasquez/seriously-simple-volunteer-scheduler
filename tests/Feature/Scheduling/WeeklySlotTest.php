<?php

namespace Tests\Feature\Scheduling;

use App\Models\WeeklySlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class WeeklySlotTest extends TestCase
{
    use RefreshDatabase;

    private const ZONE = 'America/New_York';

    public function test_a_weekly_slot_occurs_on_its_weekday(): void
    {
        $slot = WeeklySlot::factory()->create(['weekday' => 4]);

        $this->assertTrue($slot->occursOn('2026-10-15'));   // Thursday
        $this->assertFalse($slot->occursOn('2026-10-16'));  // Friday
    }

    public function test_valid_from_and_valid_until_include_their_own_dates(): void
    {
        $slot = WeeklySlot::factory()->create([
            'weekday' => 4,
            'valid_from' => '2026-10-15',
            'valid_until' => '2026-10-22',
        ]);

        $this->assertFalse($slot->occursOn('2026-10-08'));
        $this->assertTrue($slot->occursOn('2026-10-15'));
        $this->assertTrue($slot->occursOn('2026-10-22'));
        $this->assertFalse($slot->occursOn('2026-10-29'));
    }

    public function test_an_every_other_week_slot_alternates_from_its_anchor(): void
    {
        $slot = WeeklySlot::factory()->everyOtherWeek('2026-10-15')->create();

        $this->assertTrue($slot->occursOn('2026-10-01'));
        $this->assertFalse($slot->occursOn('2026-10-08'));
        $this->assertTrue($slot->occursOn('2026-10-15'));
        $this->assertFalse($slot->occursOn('2026-10-22'));
        $this->assertTrue($slot->occursOn('2026-10-29'));
    }

    public function test_an_every_other_week_slot_needs_an_anchor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WeeklySlot::factory()->create(['frequency' => 'every_other_week', 'anchor_date' => null]);
    }

    public function test_an_anchor_on_a_different_weekday_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // 2026-10-15 is a Thursday; weekday 5 is Friday.
        WeeklySlot::factory()->create(['frequency' => 'every_other_week', 'anchor_date' => '2026-10-15', 'weekday' => 5]);
    }

    public function test_a_weekday_outside_the_week_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WeeklySlot::factory()->create(['weekday' => 7]);
    }

    public function test_a_slot_that_ends_when_it_starts_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WeeklySlot::factory()->create(['start_time' => '15:30', 'end_time' => '15:30:00']);
    }

    public function test_a_slot_needs_at_least_one_person(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WeeklySlot::factory()->create(['people_needed' => 0]);
    }

    public function test_local_times_stay_put_when_daylight_saving_time_ends(): void
    {
        // US daylight saving time ends on Sunday 1 November 2026.
        $slot = WeeklySlot::factory()->create(['weekday' => 0, 'start_time' => '11:30', 'end_time' => '14:30']);

        [$before, $beforeEnd] = $slot->timesOn('2026-10-25', self::ZONE);
        [$after, $afterEnd] = $slot->timesOn('2026-11-01', self::ZONE);

        $this->assertSame('2026-10-25 15:30:00', $before->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-25 18:30:00', $beforeEnd->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 16:30:00', $after->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 19:30:00', $afterEnd->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $after->getTimezone()->getName());
    }

    public function test_a_start_time_skipped_by_daylight_saving_time_moves_forward(): void
    {
        // On Sunday 14 March 2027 New York clocks jump from 02:00 to 03:00, so 02:30 doesn't exist.
        $slot = WeeklySlot::factory()->create(['weekday' => 0, 'start_time' => '02:30', 'end_time' => '05:00']);

        [$start, $end] = $slot->timesOn('2027-03-14', self::ZONE);

        $this->assertSame('2027-03-14 07:30:00', $start->format('Y-m-d H:i:s'));  // 03:30 EDT
        $this->assertSame('2027-03-14 09:00:00', $end->format('Y-m-d H:i:s'));    // 05:00 EDT
    }

    public function test_a_slot_that_ends_before_it_starts_ends_the_next_day(): void
    {
        $slot = WeeklySlot::factory()->create(['weekday' => 6, 'start_time' => '22:00', 'end_time' => '02:00']);

        [$start, $end] = $slot->timesOn('2026-10-17', self::ZONE);

        $this->assertSame('2026-10-18 02:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-18 06:00:00', $end->format('Y-m-d H:i:s'));
    }
}
