<?php

namespace Tests\Feature\Calendar;

use App\Actions\Scheduling\GenerateShifts;
use App\Enums\AreaColor;
use App\Enums\AssignmentType;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarColumnsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Thursday 15 October 2026, 10:00 in New York.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'America/New_York'));
        Organization::factory()->create(['timezone' => 'America/New_York', 'weeks_ahead' => 2]);
    }

    public function test_generated_shifts_copy_the_slot_title(): void
    {
        $slot = WeeklySlot::factory()->create(['title' => 'Laser class helper', 'weekday' => 3]);

        app(GenerateShifts::class)->forSlot(Organization::current(), $slot);

        $this->assertSame('Laser class helper', $slot->shifts()->first()->title);
    }

    public function test_for_slot_logs_nothing_itself(): void
    {
        $slot = WeeklySlot::factory()->create(['weekday' => 3]);

        $created = app(GenerateShifts::class)->forSlot(Organization::current(), $slot);

        $this->assertSame(2, $created);
        $this->assertSame(0, ChangeLogEntry::count());
    }

    public function test_a_cancelled_shift_is_not_active_and_not_recreated(): void
    {
        $slot = WeeklySlot::factory()->create(['weekday' => 4]);
        app(GenerateShifts::class)->forSlot(Organization::current(), $slot);
        $shift = $slot->shifts()->first();
        $shift->forceFill(['cancelled_at' => now()])->save();

        app(GenerateShifts::class)->handle(Organization::current());

        $this->assertSame(0, Shift::query()->active()->whereKey($shift->id)->count());
        $this->assertSame(1, Shift::query()->where('weekly_slot_id', $slot->id)->where('local_date', $shift->local_date)->count());
    }

    public function test_open_places_and_urgency(): void
    {
        $now = CarbonImmutable::now();
        $area = Area::factory()->create();
        $shift = Shift::create([
            'area_id' => $area->id,
            'local_date' => $now->addHours(20)->toDateString(),
            'starts_at' => $now->addHours(20),
            'ends_at' => $now->addHours(23),
            'people_needed' => 2,
            'shadow_spots' => 1,
        ]);
        $shift->assignments()->create(['user_id' => User::factory()->create()->id, 'type' => AssignmentType::Regular]);
        $shift->assignments()->create(['user_id' => User::factory()->create()->id, 'type' => AssignmentType::Shadow]);

        $this->assertSame(1, $shift->filledCount());
        $this->assertSame(1, $shift->openPlaces());
        $this->assertTrue($shift->isUrgent($now));

        $shift->forceFill(['starts_at' => $now->addHours(30), 'ends_at' => $now->addHours(33)])->save();
        $this->assertFalse($shift->fresh()->isUrgent($now));

        $shift->forceFill(['starts_at' => $now->subHour()])->save();
        $this->assertFalse($shift->fresh()->isUrgent($now));
    }

    public function test_new_areas_get_the_next_unused_color(): void
    {
        Area::factory()->create(['color' => AreaColor::Blue]);

        $this->assertSame(AreaColor::Orange, AreaColor::next());
    }

    public function test_next_color_wraps_round_when_all_are_used(): void
    {
        foreach (AreaColor::cases() as $color) {
            Area::factory()->create(['color' => $color]);
        }

        $this->assertSame(AreaColor::Blue, AreaColor::next());
    }

    public function test_colors_have_css_classes_and_labels(): void
    {
        $this->assertSame('area-blue', AreaColor::Blue->cssClass());
        $this->assertSame('Teal', AreaColor::Teal->label());
    }
}
