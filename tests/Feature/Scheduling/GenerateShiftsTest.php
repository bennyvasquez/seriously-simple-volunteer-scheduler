<?php

namespace Tests\Feature\Scheduling;

use App\Actions\Scheduling\GenerateShifts;
use App\Enums\AssignmentType;
use App\Models\Area;
use App\Models\AreaClosure;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateShiftsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        // Thursday 15 October 2026, 10:00 in New York.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'America/New_York'));
        $this->organization = Organization::factory()->create(['timezone' => 'America/New_York', 'weeks_ahead' => 2]);
    }

    private function generate(): int
    {
        return app(GenerateShifts::class)->handle($this->organization->fresh());
    }

    /** @return list<string> */
    private function localDates(): array
    {
        return Shift::query()->orderBy('starts_at')->get()
            ->map(fn (Shift $shift) => $shift->local_date->toDateString())
            ->all();
    }

    public function test_it_creates_shifts_for_the_coming_weeks(): void
    {
        $slot = WeeklySlot::factory()->create(['weekday' => 4, 'people_needed' => 2, 'shadow_spots' => 1]);

        $this->assertSame(2, $this->generate());
        $this->assertSame(['2026-10-15', '2026-10-22'], $this->localDates());

        $first = Shift::query()->orderBy('starts_at')->first();
        $this->assertSame('2026-10-15 19:30:00', $first->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-15 22:30:00', $first->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame($slot->area_id, $first->area_id);
        $this->assertSame(2, $first->people_needed);
        $this->assertSame(1, $first->shadow_spots);
    }

    public function test_running_it_again_creates_nothing_new(): void
    {
        $slot = WeeklySlot::factory()->create(['weekday' => 4]);
        $slot->regulars()->create(['user_id' => User::factory()->create()->id, 'starts_on' => '2026-01-01']);

        $this->generate();

        $this->assertSame(0, $this->generate());
        $this->assertSame(2, Shift::count());
        $this->assertSame(2, Assignment::count());
    }

    public function test_it_leaves_existing_shifts_alone_when_the_slot_changes(): void
    {
        $slot = WeeklySlot::factory()->create(['weekday' => 4, 'start_time' => '15:30', 'end_time' => '18:30']);
        $this->generate();

        $slot->update(['start_time' => '16:00']);
        $this->generate();

        $this->assertSame('2026-10-15 19:30:00', Shift::query()->orderBy('starts_at')->first()->starts_at->format('Y-m-d H:i:s'));
    }

    public function test_it_fills_in_regulars_who_are_active_on_each_date(): void
    {
        $this->organization->update(['weeks_ahead' => 3]);
        $slot = WeeklySlot::factory()->create(['weekday' => 4, 'people_needed' => 2]);
        $always = User::factory()->create();
        $leaving = User::factory()->create();
        $joining = User::factory()->create();
        $slot->regulars()->create(['user_id' => $always->id, 'starts_on' => '2026-01-01']);
        $slot->regulars()->create(['user_id' => $leaving->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-10-15']);
        $slot->regulars()->create(['user_id' => $joining->id, 'starts_on' => '2026-10-29']);

        $this->generate();

        $peopleOn = fn (string $date) => Shift::query()->whereDate('local_date', $date)->firstOrFail()
            ->assignments()->orderBy('user_id')->pluck('user_id')->all();

        $this->assertSame([$always->id, $leaving->id], $peopleOn('2026-10-15'));
        $this->assertSame([$always->id], $peopleOn('2026-10-22'));
        $this->assertSame([$always->id, $joining->id], $peopleOn('2026-10-29'));
        $this->assertSame(AssignmentType::Regular, Assignment::query()->first()->type);
    }

    public function test_it_skips_dates_when_the_area_is_closed(): void
    {
        $this->organization->update(['weeks_ahead' => 3]);
        $slot = WeeklySlot::factory()->create(['weekday' => 4]);
        AreaClosure::create(['area_id' => $slot->area_id, 'starts_on' => '2026-10-22', 'ends_on' => '2026-10-22', 'reason' => 'Holiday']);
        AreaClosure::create(['area_id' => Area::factory()->create()->id, 'starts_on' => '2026-10-29', 'ends_on' => '2026-10-29', 'reason' => 'Other area']);

        $this->generate();

        $this->assertSame(['2026-10-15', '2026-10-29'], $this->localDates());
    }

    public function test_it_stops_at_the_last_valid_date(): void
    {
        $this->organization->update(['weeks_ahead' => 3]);
        WeeklySlot::factory()->create(['weekday' => 4, 'valid_until' => '2026-10-22']);

        $this->generate();

        $this->assertSame(['2026-10-15', '2026-10-22'], $this->localDates());
    }

    public function test_it_follows_every_other_week_slots(): void
    {
        $this->organization->update(['weeks_ahead' => 4]);
        WeeklySlot::factory()->everyOtherWeek('2026-10-15')->create();

        $this->generate();

        $this->assertSame(['2026-10-15', '2026-10-29'], $this->localDates());
    }

    public function test_it_puts_alternating_regulars_on_their_own_weeks(): void
    {
        $this->organization->update(['weeks_ahead' => 4]);
        $slot = WeeklySlot::factory()->create(['weekday' => 4]);
        $first = User::factory()->create();
        $second = User::factory()->create();
        $slot->regulars()->create(['user_id' => $first->id, 'starts_on' => '2026-01-01', 'frequency' => 'every_other_week', 'anchor_date' => '2026-10-15']);
        $slot->regulars()->create(['user_id' => $second->id, 'starts_on' => '2026-01-01', 'frequency' => 'every_other_week', 'anchor_date' => '2026-10-22']);

        $this->generate();

        $peopleOn = fn (string $date) => Shift::query()->whereDate('local_date', $date)->firstOrFail()
            ->assignments()->pluck('user_id')->all();

        $this->assertSame([$first->id], $peopleOn('2026-10-15'));
        $this->assertSame([$second->id], $peopleOn('2026-10-22'));
        $this->assertSame([$first->id], $peopleOn('2026-10-29'));
        $this->assertSame([$second->id], $peopleOn('2026-11-05'));
    }

    public function test_it_keeps_local_times_across_the_daylight_saving_change(): void
    {
        $this->organization->update(['weeks_ahead' => 3]);
        WeeklySlot::factory()->create(['weekday' => 0, 'start_time' => '11:30', 'end_time' => '14:30']);

        $this->generate();

        $starts = Shift::query()->orderBy('starts_at')->get()
            ->map(fn (Shift $shift) => $shift->starts_at->format('Y-m-d H:i'))
            ->all();
        $this->assertSame(['2026-10-18 15:30', '2026-10-25 15:30', '2026-11-01 16:30'], $starts);
    }

    public function test_it_does_not_create_shifts_that_have_already_started(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 16:00', 'America/New_York'));
        WeeklySlot::factory()->create(['weekday' => 4, 'start_time' => '15:30', 'end_time' => '18:30']);

        $this->generate();

        $this->assertSame(['2026-10-22'], $this->localDates());
    }

    public function test_it_uses_the_organization_date_not_the_utc_date(): void
    {
        // 23:00 on Wednesday 14 October in New York is already Thursday in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 23:00', 'America/New_York'));
        $this->organization->update(['weeks_ahead' => 1]);
        WeeklySlot::factory()->create(['weekday' => 3, 'start_time' => '23:30', 'end_time' => '23:45']);

        $this->generate();

        $this->assertSame(['2026-10-14'], $this->localDates());
    }

    public function test_a_run_that_creates_shifts_is_logged_once(): void
    {
        WeeklySlot::factory()->create(['weekday' => 4]);

        $created = $this->generate();
        $this->generate();

        $entry = ChangeLogEntry::query()->ofAction('shifts.generated')->sole();
        $this->assertTrue($entry->isBySystem());
        $this->assertSame($created, $entry->details['created']);
        $this->assertSame('2026-10-15', $entry->details['from']);
        $this->assertSame('2026-10-28', $entry->details['to']);
    }

    public function test_it_leaves_out_regulars_who_have_been_turned_off(): void
    {
        $slot = WeeklySlot::factory()->create(['weekday' => 4, 'people_needed' => 2]);
        $active = User::factory()->create();
        $turnedOff = User::factory()->create(['deactivated_at' => now()]);
        $slot->regulars()->create(['user_id' => $active->id, 'starts_on' => '2026-01-01']);
        $slot->regulars()->create(['user_id' => $turnedOff->id, 'starts_on' => '2026-01-01']);

        $this->generate();

        $this->assertSame(2, Assignment::count());
        $this->assertFalse(Assignment::query()->where('user_id', $turnedOff->id)->exists());
    }

    public function test_generation_puts_every_regular_on(): void
    {
        $slot = WeeklySlot::factory()->create(['weekday' => 4, 'people_needed' => 2]);
        $ids = [];

        foreach (range(1, 3) as $ignored) {
            $user = User::factory()->create();
            $ids[] = $user->id;
            $slot->regulars()->create(['user_id' => $user->id, 'starts_on' => '2026-01-01']);
        }

        $this->generate();

        $this->assertGreaterThan(0, Shift::query()->count());
        foreach (Shift::query()->get() as $shift) {
            $this->assertEqualsCanonicalizing($ids, $shift->assignments()->pluck('user_id')->all());
        }
    }
}
