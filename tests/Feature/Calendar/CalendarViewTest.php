<?php

namespace Tests\Feature\Calendar;

use App\Actions\Accounts\GrantRole;
use App\Calendar\CalendarWeek;
use App\Enums\AreaColor;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Models\Area;
use App\Models\AreaClosure;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class CalendarViewTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private Area $frontDesk;

    private Area $hostedEvents;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday, October 14, 2026, 9am in Detroit, so the shifts below are all in the future.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));
        Organization::factory()->create(['timezone' => self::TIMEZONE]);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk', 'color' => AreaColor::Blue]);
        $this->hostedEvents = Area::factory()->create(['name' => 'Hosted events', 'color' => AreaColor::Orange]);
    }

    private function coordinator(Role $role = Role::Coordinator, ?Area $area = null): User
    {
        $user = User::factory()->create();
        app(GrantRole::class)->handle(null, $user, $role, $area);
        $this->enableTwoFactor($user);

        return $user;
    }

    private function shiftOn(string $date, string $start, string $end, ?Area $area = null, int $peopleNeeded = 1, int $shadowSpots = 0, ?string $title = null, ?WeeklySlot $slot = null): Shift
    {
        $startsAt = CarbonImmutable::parse("{$date} {$start}", self::TIMEZONE);
        $endsAt = CarbonImmutable::parse("{$date} {$end}", self::TIMEZONE);

        return Shift::query()->create([
            'area_id' => ($area ?? $this->frontDesk)->id,
            'weekly_slot_id' => $slot?->id,
            'local_date' => $date,
            'starts_at' => $startsAt->utc(),
            'ends_at' => ($endsAt->lessThan($startsAt) ? $endsAt->addDay() : $endsAt)->utc(),
            'people_needed' => $peopleNeeded,
            'shadow_spots' => $shadowSpots,
            'title' => $title,
        ]);
    }

    private function fill(Shift $shift, string $name, AssignmentType $type = AssignmentType::Regular): void
    {
        $shift->assignments()->create(['user_id' => User::factory()->create(['name' => $name])->id, 'type' => $type]);
    }

    public function test_the_week_starts_on_sunday_and_shows_shifts_in_local_time(): void
    {
        $shift = $this->shiftOn('2026-10-22', '18:30', '21:30', area: $this->frontDesk);

        $this->actingAs($this->coordinator())->get(route('calendar.week', ['date' => '2026-10-21']))
            ->assertOk()
            ->assertSeeInOrder(['SUN', '18', 'MON', '19', 'SAT', '24'])
            ->assertSee('October 18 to 24, 2026')
            ->assertSee('6:30 to 9:30');
    }

    public function test_open_and_urgent_shifts_are_marked(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00', 'America/Detroit'));
        $this->shiftOn('2026-10-18', '11:30', '14:30', peopleNeeded: 1);
        $coordinator = $this->coordinator();

        $html = $this->actingAs($coordinator)->get(route('calendar.week'))->getContent();

        // Saturday the 17th is the last day of this week, so the Sunday shift shows in Needs attention here.
        $this->assertStringContainsString('urgent', $html);
        $this->assertStringContainsString('1 open', $html);

        // On its own week the block itself carries both marks.
        $this->actingAs($coordinator)->get(route('calendar.week', ['date' => '2026-10-18']))
            ->assertSee('class="ev area-blue open urgent"', false)
            ->assertSee('1 open, tomorrow');
    }

    public function test_cancelled_shifts_show_as_cancelled_and_are_not_open_or_urgent(): void
    {
        $shift = $this->shiftOn('2026-10-15', '18:30', '21:30');
        $shift->forceFill(['cancelled_at' => now(), 'cancel_reason' => 'Power outage'])->save();
        $coordinator = $this->coordinator();

        $this->actingAs($coordinator)->get(route('calendar.week', ['date' => '2026-10-15']))
            ->assertOk()
            ->assertSee('6:30 to 9:30pm')
            ->assertSee('Cancelled: Power outage')
            // Screen readers hear "Cancelled" from the visible line, which is part of the block's name, not only the strike-through.
            ->assertSee('<span class="vh">, </span><span>Cancelled: Power outage</span>', false)
            ->assertSee('class="ev area-blue cancelled"', false)
            ->assertDontSee('1 open')
            ->assertDontSee('urgent')
            ->assertDontSee('No regular');

        $this->actingAs($coordinator)->get(route('calendar.week'))->assertDontSee('Front desk, 1 open');
        $this->assertSame(0, $shift->fresh()->openPlaces());
    }

    public function test_the_month_counts_cancelled_dates_apart_from_shifts(): void
    {
        $this->shiftOn('2026-10-22', '10:00', '12:00');
        $shift = $this->shiftOn('2026-10-22', '18:30', '21:30');
        $shift->forceFill(['cancelled_at' => now(), 'cancel_reason' => 'Power outage'])->save();

        $this->actingAs($this->coordinator())->get(route('calendar.month', ['month' => '2026-10']))
            ->assertOk()->assertSee('1 Front desk shift')->assertSee('1 Front desk shift cancelled')->assertSee('1 open');
    }

    public function test_the_area_filter_hides_unticked_areas(): void
    {
        $this->shiftOn('2026-10-22', '18:30', '21:30', area: $this->frontDesk);
        $this->shiftOn('2026-10-22', '10:00', '13:00', area: $this->hostedEvents);
        $coordinator = $this->coordinator();

        $this->actingAs($coordinator)->get(route('calendar.week', ['date' => '2026-10-22']))
            ->assertSee('6:30 to 9:30pm')
            ->assertSee('10am to 1pm');

        $this->actingAs($coordinator)->get(route('calendar.week', ['date' => '2026-10-22', 'areas' => [$this->frontDesk->id]]))
            ->assertSee('6:30 to 9:30pm')
            ->assertDontSee('10am to 1pm');

        // The form always sends an empty entry, so unticking every area shows nothing rather than everything.
        $this->actingAs($coordinator)->get(route('calendar.week', ['date' => '2026-10-22', 'areas' => ['']]))
            ->assertDontSee('6:30 to 9:30pm')
            ->assertDontSee('10am to 1pm')
            ->assertSee('No areas are ticked.');
    }

    public function test_the_month_shows_counts_and_closures(): void
    {
        AreaClosure::query()->create(['area_id' => $this->frontDesk->id, 'starts_on' => '2026-11-26', 'ends_on' => '2026-11-29', 'reason' => 'Thanksgiving']);
        $this->shiftOn('2026-11-05', '15:30', '18:30');
        $this->shiftOn('2026-11-05', '18:30', '21:30');

        $this->actingAs($this->coordinator())->get(route('calendar.month', ['month' => '2026-11']))
            ->assertOk()
            ->assertSee('November 2026')
            ->assertSeeInOrder(['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'])
            ->assertSee('Closed: Thanksgiving')
            ->assertSee('2 Front desk shifts')
            ->assertSee('2 open')
            ->assertSee('Dec 1')
            ->assertSee('href="'.route('calendar.week', ['date' => '2026-11-05']).'" class="num"', false);
    }

    public function test_an_area_lead_sees_every_area(): void
    {
        $this->shiftOn('2026-10-22', '10:00', '13:00', area: $this->hostedEvents);

        $this->actingAs($this->coordinator(Role::AreaLead, $this->frontDesk))->get(route('calendar.week', ['date' => '2026-10-22']))
            ->assertOk()
            ->assertSee('10am to 1pm');
    }

    public function test_blocks_name_their_area_and_list_regulars_before_shadows(): void
    {
        $shift = $this->shiftOn('2026-10-22', '18:30', '21:30', shadowSpots: 2, title: 'Laser class helper');
        $this->fill($shift, 'Robin Example', AssignmentType::Shadow);
        $this->fill($shift, 'Sam Example');

        $this->actingAs($this->coordinator())->get(route('calendar.week', ['date' => '2026-10-22']))
            ->assertSeeInOrder(['6:30 to 9:30pm', 'Front desk', 'Laser class helper', 'Sam', 'Shadow: Robin', '1 shadow spot open'])
            // The block's name is its visible text (so speech input users can say what they see), plus the date, which is hidden.
            ->assertDontSee('aria-label="Front desk', false)
            ->assertSee('<span class="vh">, Thursday, October 22</span>', false)
            ->assertDontSee('1 open');
    }

    public function test_the_day_view_shows_one_day(): void
    {
        $this->shiftOn('2026-10-22', '18:30', '21:30');
        $this->shiftOn('2026-10-23', '10:00', '13:00');

        $this->actingAs($this->coordinator())->get(route('calendar.day', ['date' => '2026-10-22']))
            ->assertOk()
            ->assertSee('Thursday, October 22, 2026')
            ->assertSee('6:30 to 9:30pm')
            ->assertDontSee('10am to 1pm');
    }

    public function test_a_bad_date_falls_back_to_this_week(): void
    {
        $this->actingAs($this->coordinator())->get(route('calendar.week', ['date' => 'not-a-date']))
            ->assertOk()
            ->assertSee('October 11 to 17, 2026');
    }

    public function test_overlapping_shifts_sit_side_by_side(): void
    {
        $morgan = $this->shiftOn('2026-10-22', '15:30', '18:30');
        $casey = $this->shiftOn('2026-10-22', '16:00', '18:30');
        $sam = $this->shiftOn('2026-10-22', '18:30', '21:30');

        $week = CalendarWeek::for(CarbonImmutable::parse('2026-10-22'), Organization::current(), [$this->frontDesk->id]);
        $columns = $week->columnsFor($week->shiftsOn('2026-10-22'));

        $this->assertSame([0, 2], $columns[$morgan->id]);
        $this->assertSame([1, 2], $columns[$casey->id]);
        $this->assertSame([0, 1], $columns[$sam->id]);
    }

    public function test_the_hours_cover_every_shift_and_at_least_8am_to_10pm(): void
    {
        $organization = Organization::current();
        $areas = [$this->frontDesk->id];

        $this->assertSame([8, 22], CalendarWeek::for(CarbonImmutable::parse('2026-10-22'), $organization, $areas)->hourRange());

        $this->shiftOn('2026-10-20', '06:30', '09:00');
        $this->shiftOn('2026-10-22', '21:00', '23:15');

        $this->assertSame([5, 24], CalendarWeek::for(CarbonImmutable::parse('2026-10-22'), $organization, $areas)->hourRange());
    }

    public function test_the_week_page_runs_the_same_number_of_queries_however_many_shifts_it_has(): void
    {
        $coordinator = $this->coordinator();
        $slot = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id]);

        // One shift that uses every eager load (slot, people, open place), so adding more can only change the count if something queries per shift.
        $first = $this->shiftOn('2026-10-19', '15:30', '18:30', peopleNeeded: 2, slot: $slot);
        $this->fill($first, 'Sam Example');
        $this->fill($first, 'Robin Example', AssignmentType::Shadow);

        $count = function () use ($coordinator): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($coordinator)->get(route('calendar.week', ['date' => '2026-10-22']))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        // The first request also records when the coordinator was last seen, which is throttled, so measure from the second.
        $count();
        $few = $count();

        foreach (['2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24'] as $date) {
            $shift = $this->shiftOn($date, '15:30', '18:30', peopleNeeded: 2, area: $this->hostedEvents, slot: $slot);
            $this->fill($shift, 'Sam Example');
            $this->fill($shift, 'Robin Example', AssignmentType::Shadow);
        }

        $this->assertSame($few, $count());
    }

    public function test_needs_attention_lists_open_shifts_and_slots_without_a_regular(): void
    {
        $full = $this->shiftOn('2026-10-15', '10:00', '13:00');
        $this->fill($full, 'Sam Example');
        $this->shiftOn('2026-10-16', '15:30', '18:30');
        $this->shiftOn('2026-11-20', '15:30', '18:30');

        $staffed = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'weekday' => 2, 'start_time' => '09:00', 'end_time' => '11:00']);
        SlotRegular::query()->create(['weekly_slot_id' => $staffed->id, 'user_id' => User::factory()->create()->id, 'starts_on' => '2026-10-01']);
        WeeklySlot::factory()->create(['area_id' => $this->hostedEvents->id, 'weekday' => 4, 'start_time' => '15:30', 'end_time' => '18:30']);

        $this->actingAs($this->coordinator())->get(route('calendar.week'))
            ->assertSeeInOrder(['Needs attention', 'Fri Oct 16, 3:30pm', 'Front desk, 1 open', 'Hosted events, Thursdays 3:30 to 6:30pm', 'No regular'])
            ->assertDontSee('Thu Oct 15, 10am')
            ->assertDontSee('Fri Nov 20')
            ->assertDontSee('Tuesdays 9 to 11am');
    }

    public function test_home_reaches_the_calendar_through_the_menu_for_everyone(): void
    {
        $this->actingAs($this->coordinator())->get('/')->assertSee(route('calendar.week'))->assertDontSee('>Calendar<', false);
        $this->actingAs(User::factory()->create())->get('/')->assertSee(route('calendar.week'))->assertDontSee('>Calendar<', false);
    }

    public function test_a_week_across_the_november_time_change_keeps_local_times(): void
    {
        $slot = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'weekday' => 0, 'start_time' => '18:30', 'end_time' => '21:30']);
        $this->shiftOn('2026-10-25', '18:30', '21:30', slot: $slot);
        $this->shiftOn('2026-11-01', '18:30', '21:30', slot: $slot);
        $coordinator = $this->coordinator();

        $this->actingAs($coordinator)->get(route('calendar.week', ['date' => '2026-10-29']))
            ->assertSee('October 25 to 31, 2026')
            ->assertSee('6:30 to 9:30pm');

        $this->actingAs($coordinator)->get(route('calendar.week', ['date' => '2026-11-01']))
            ->assertSee('November 1 to 7, 2026')
            ->assertSee('6:30 to 9:30pm')
            ->assertDontSee('5:30 to 8:30pm')
            ->assertDontSee('7:30 to 10:30pm');
    }

    public function test_a_shift_past_midnight_runs_the_grid_to_the_end_of_the_day(): void
    {
        $this->shiftOn('2026-10-22', '20:00', '01:00');

        $week = CalendarWeek::for(CarbonImmutable::parse('2026-10-22'), Organization::current(), [$this->frontDesk->id]);

        $this->assertSame([8, 24], $week->hourRange());
    }

    public function test_unknown_or_invalid_area_ids_are_ignored(): void
    {
        $this->shiftOn('2026-10-22', '18:30', '21:30', area: $this->frontDesk);
        $this->shiftOn('2026-10-22', '10:00', '13:00', area: $this->hostedEvents);
        $coordinator = $this->coordinator();

        $this->actingAs($coordinator)->get(route('calendar.week', ['date' => '2026-10-22', 'areas' => [$this->frontDesk->id, 999, 'abc']]))
            ->assertOk()
            ->assertSee('6:30 to 9:30pm')
            ->assertDontSee('10am to 1pm');

        $this->actingAs($coordinator)->get(route('calendar.week', ['date' => '2026-10-22', 'areas' => [999, 'abc']]))
            ->assertOk()
            ->assertDontSee('6:30 to 9:30pm')
            ->assertSee('No areas are ticked.');
    }

    public function test_needs_attention_keeps_the_area_filter_and_counts_only_regulars_in_the_window(): void
    {
        $shift = $this->shiftOn('2026-10-16', '15:30', '18:30');
        $later = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'weekday' => 2, 'start_time' => '09:00', 'end_time' => '11:00']);
        SlotRegular::query()->create(['weekly_slot_id' => $later->id, 'user_id' => User::factory()->create()->id, 'starts_on' => '2026-12-01']);

        $this->actingAs($this->coordinator())->get(route('calendar.week', ['areas' => [$this->frontDesk->id]]))
            ->assertSee('href="'.e(route('calendar.shift', [$shift, 'areas' => [$this->frontDesk->id]])).'"', false)
            ->assertSeeInOrder(['Front desk, Tuesdays 9 to 11am', 'No regular']);
    }

    public function test_a_removed_person_shows_as_removed_person_on_the_grid_and_the_shift_panel(): void
    {
        $shift = $this->shiftOn('2026-10-07', '18:30', '21:30');
        $shift->assignments()->create(['user_id' => User::removedPerson()->id, 'type' => AssignmentType::Regular]);
        $coordinator = $this->coordinator();

        $this->actingAs($coordinator)->get(route('calendar.week', ['date' => '2026-10-07']))
            ->assertOk()->assertSee('Removed person')->assertDontSee('Removed P.');
        $this->actingAs($coordinator)->get(route('calendar.shift', $shift))
            ->assertOk()->assertSee('Removed person')->assertDontSee('Removed P.');
    }
}
