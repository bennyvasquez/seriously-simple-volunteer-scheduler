<?php

namespace Tests\Feature\Screens;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\AddRegular;
use App\Actions\Scheduling\CancelShift;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\GenerateShifts;
use App\Actions\Scheduling\ReleaseShifts;
use App\Actions\Scheduling\TakeShift;
use App\Cover\CoverEligibility;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Http\Controllers\Volunteer\HomeController;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class VolunteerScreensTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private Area $frontDesk;

    private User $coordinator;

    private User $sam;

    private User $robin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutDefer();

        // Wednesday, October 14, 2026, 9am in Detroit.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));
        Organization::factory()->create(['name' => 'Example Makerspace', 'timezone' => self::TIMEZONE, 'weeks_ahead' => 8]);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk']);
        $this->coordinator = User::factory()->create(['name' => 'Chris Coordinator']);
        app(GrantRole::class)->handle(null, $this->coordinator, Role::Coordinator);
        $this->enableTwoFactor($this->coordinator);
        $this->sam = User::factory()->create(['name' => 'Sam Example']);
        $this->robin = User::factory()->create(['name' => 'Robin Example']);
    }

    private function shift(string $date, string $from = '18:30', string $to = '21:30', int $needed = 1, ?Area $area = null): Shift
    {
        return app(CreateOneTimeShift::class)->handle($this->coordinator, $area ?? $this->frontDesk, $date, $from, $to, $needed, 1, null);
    }

    private function on(Shift $shift, User $person, AssignmentType $type = AssignmentType::Regular): void
    {
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $person->id, 'type' => $type]);
    }

    private function sees(User $user, string $url = '/')
    {
        return $this->actingAs($user)->get($url)->assertOk();
    }

    public function test_home_lists_your_next_14_days_with_cant_make_it(): void
    {
        $slot = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'weekday' => 4, 'start_time' => '18:30', 'end_time' => '21:30']);
        app(GenerateShifts::class)->handle(Organization::current());
        app(AddRegular::class)->handle(null, $slot, $this->sam, '2026-10-15');
        $oneTime = $this->shift('2026-10-17', '14:30', '20:30');
        $this->on($oneTime, $this->sam);
        $shadowed = $this->shift('2026-10-20');
        $this->on($shadowed, $this->sam, AssignmentType::Shadow);
        $far = $this->shift('2026-11-20');
        $this->on($far, $this->sam);
        $cancelled = $this->shift('2026-10-21');
        $this->on($cancelled, $this->sam);
        app(CancelShift::class)->handle($this->coordinator, $cancelled, 'Closed');
        $past = Shift::create(['area_id' => $this->frontDesk->id, 'local_date' => '2026-10-14', 'starts_at' => '2026-10-14 11:00:00', 'ends_at' => '2026-10-14 12:00:00', 'people_needed' => 1, 'shadow_spots' => 0]);
        $this->on($past, $this->sam);

        $response = $this->sees($this->sam);
        $response->assertSeeInOrder(['Your next shifts', 'Thursday, October 15', 'Every Thursday', 'Front desk, 6:30 to 9:30pm', 'Saturday, October 17', 'One time', 'Front desk, 2:30 to 8:30pm', 'Tuesday, October 20', 'Shadowing', 'Thursday, October 22', 'Every Thursday', 'All my shifts']);
        $response->assertSee('href="'.url('/shifts/'.$oneTime->id.'/release').'"', false);
        $response->assertSee('href="'.url('/shifts/'.$shadowed->id.'/release').'"', false);
        $response->assertSee("Can't make it");
        $response->assertSee('href="'.route('my-shifts').'"', false);
        $response->assertDontSee('Tuesday, October 21');
        $response->assertDontSee('November 20');
        $response->assertDontSee('7 to 8am');
        $this->assertSame(4, substr_count($response->getContent(), '/release"'));
    }

    public function test_home_says_so_when_you_are_on_nothing(): void
    {
        $this->sees($this->sam)->assertSee("You're not on any shifts in the next two weeks.", false)->assertSee('No open shifts in the next two weeks.');
    }

    public function test_home_lists_open_shifts_you_can_take(): void
    {
        $coverShift = $this->shift('2026-10-22');
        $this->on($coverShift, $this->robin);
        app(ReleaseShifts::class)->one($this->robin, $coverShift, null);
        $plain = $this->shift('2026-10-16', '10:00', '12:00');
        $rotation = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'weekday' => 6, 'start_time' => '14:30', 'end_time' => '20:30', 'is_rotation' => true]);
        $noRegular = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'weekday' => 0, 'start_time' => '11:30', 'end_time' => '14:30']);
        app(GenerateShifts::class)->handle(Organization::current());

        $mine = $this->shift('2026-10-19', '18:00', '20:00');
        $this->on($mine, $this->sam);
        $overlap = $this->shift('2026-10-19', '19:00', '21:00', area: Area::factory()->create(['name' => 'Hosted events']));
        $full = $this->shift('2026-10-18', '09:00', '10:00');
        $this->on($full, $this->robin);
        $cancelled = $this->shift('2026-10-23', '09:00', '10:00');
        app(CancelShift::class)->handle($this->coordinator, $cancelled, 'Closed');
        $started = Shift::create(['area_id' => $this->frontDesk->id, 'local_date' => '2026-10-14', 'starts_at' => '2026-10-14 12:00:00', 'ends_at' => '2026-10-14 16:00:00', 'people_needed' => 1, 'shadow_spots' => 0]);
        $beyond = $this->shift('2026-11-30', '09:00', '10:00');

        $response = $this->sees($this->sam);
        $response->assertSeeInOrder(['Open shifts you can take', 'Thursday, October 22', 'Needs cover', 'Robin can\'t make it', 'Friday, October 16', 'Saturday, October 17', 'Sunday, October 18', 'Sunday, October 25', 'No regular']);
        $response->assertSee('href="'.url('/shifts/'.$coverShift->id.'/take').'"', false);
        $response->assertSee('Take this shift');

        $open = app(HomeController::class)->openShifts($this->sam);
        $this->assertSame([$coverShift->id], $open->filter(fn (Shift $shift) => $shift->cover_names !== [])->pluck('id')->all());
        $this->assertSame($coverShift->id, $open->first()->id);
        $this->assertNotContains($mine->id, $open->pluck('id')->all());
        $this->assertNotContains($overlap->id, $open->pluck('id')->all());
        $this->assertNotContains($full->id, $open->pluck('id')->all());
        $this->assertNotContains($cancelled->id, $open->pluck('id')->all());
        $this->assertNotContains($started->id, $open->pluck('id')->all());
        $this->assertNotContains($beyond->id, $open->pluck('id')->all());
        $this->assertContains($plain->id, $open->pluck('id')->all());

        $this->assertTrue($open->firstWhere('weekly_slot_id', $noRegular->id)->has_no_regular);
        $this->assertFalse($open->firstWhere('weekly_slot_id', $rotation->id)->has_no_regular);
        $this->assertFalse($open->firstWhere('id', $plain->id)->has_no_regular);

        // "No regular" agrees with the weekly choices TakeShift offers.
        foreach ($open as $shift) {
            $this->assertSame(count(app(TakeShift::class)->choices($shift)) > 1, $shift->has_no_regular, 'Shift '.$shift->id);
        }
    }

    public function test_a_rotation_date_is_a_plain_open_shift(): void
    {
        WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'weekday' => 6, 'start_time' => '14:30', 'end_time' => '20:30', 'is_rotation' => true]);
        app(GenerateShifts::class)->handle(Organization::current());

        $this->sees($this->sam)->assertSee('Saturday, October 17')->assertDontSee('No regular')->assertDontSee('Needs cover');
    }

    public function test_the_releaser_sees_their_own_open_shift_and_can_take_it_back(): void
    {
        $shift = $this->shift('2026-10-22');
        $this->on($shift, $this->robin);
        app(ReleaseShifts::class)->one($this->robin, $shift, null);

        $this->sees($this->robin)->assertSee('Needs cover')->assertSee('href="'.url('/shifts/'.$shift->id.'/take').'"', false);
    }

    public function test_home_keeps_the_links_staff_need(): void
    {
        $response = $this->sees($this->coordinator);
        $response->assertSee(route('calendar.week'), false)->assertSee(route('people.index'), false)->assertSee(route('change-log.index'), false)
            ->assertSee(route('account.show'), false)->assertSee(route('account.emails'), false)->assertSee('Log out');

        $volunteer = $this->sees($this->sam);
        $volunteer->assertDontSee(route('calendar.week'), false)->assertDontSee(route('people.index'), false)->assertDontSee(route('change-log.index'), false)
            ->assertSee('Login and security')->assertSee('Emails')->assertSee('Log out');
    }

    public function test_all_my_shifts_lists_every_future_shift(): void
    {
        $dates = ['2026-10-15', '2026-11-30', '2026-12-04', '2027-01-08'];

        foreach ($dates as $date) {
            $this->on($this->shift($date), $this->sam);
        }

        $past = Shift::create(['area_id' => $this->frontDesk->id, 'local_date' => '2026-10-10', 'starts_at' => '2026-10-10 22:30:00', 'ends_at' => '2026-10-11 01:30:00', 'people_needed' => 1, 'shadow_spots' => 0]);
        $this->on($past, $this->sam);
        $this->on($this->shift('2026-10-16'), $this->robin);
        $cancelled = $this->shift('2026-12-11');
        $this->on($cancelled, $this->sam);
        app(CancelShift::class)->handle($this->coordinator, $cancelled, 'Closed');

        $response = $this->sees($this->sam, '/my-shifts');
        $response->assertSeeInOrder(['October 2026', 'Thursday, October 15', 'November 2026', 'Monday, November 30', 'December 2026', 'Friday, December 4', 'January 2027', 'Friday, January 8']);
        $response->assertSee('href="'.route('home').'"', false);
        $response->assertDontSee('October 10')->assertDontSee('October 16')->assertDontSee('December 11');
        $this->assertSame(4, substr_count($response->getContent(), '/release"'));
    }

    public function test_all_my_shifts_says_so_when_there_are_none(): void
    {
        $this->sees($this->sam, '/my-shifts')->assertSee("You're not on any shifts coming up.", false);
    }

    public function test_the_in_memory_refusal_reads_the_same_as_the_queried_one(): void
    {
        $mine = $this->shift('2026-10-19', '18:00', '20:00');
        $this->on($mine, $this->sam);
        $shadow = $this->shift('2026-10-20');
        $this->on($shadow, $this->sam, AssignmentType::Shadow);
        $this->shift('2026-10-19', '19:00', '21:00', area: Area::factory()->create(['name' => 'Hosted events']));
        $this->shift('2026-10-21');

        $loaded = Shift::query()->with(['area', 'assignments' => fn ($assignments) => $assignments->where('user_id', $this->sam->id)])
            ->active()->whereHas('assignments', fn ($assignments) => $assignments->where('user_id', $this->sam->id))->get();

        foreach (Shift::query()->get() as $shift) {
            $this->assertSame(CoverEligibility::refusal($this->sam, $shift), CoverEligibility::refusal($this->sam, $shift, $loaded), 'Shift '.$shift->id);
        }
    }

    public function test_guests_are_sent_to_log_in(): void
    {
        $this->get('/my-shifts')->assertRedirect(route('login'));
    }

    public function test_open_shifts_runs_a_flat_number_of_queries(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(HomeController::class)->openShifts($this->sam);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'weekday' => 4, 'start_time' => '18:30', 'end_time' => '21:30']);
        app(GenerateShifts::class)->handle(Organization::current());
        $this->on($this->shift('2026-10-15', '08:00', '09:00'), $this->robin);
        $mine = $this->shift('2026-10-16');
        $this->on($mine, $this->sam);

        for ($i = 0; $i < 2; $i++) {
            $released = $this->shift('2026-10-2'.$i, '10:00', '12:00', area: Area::factory()->create());
            $this->on($released, $this->robin);
            app(ReleaseShifts::class)->one($this->robin, $released, null);
        }

        $few = $count();
        $this->assertGreaterThan(0, app(HomeController::class)->openShifts($this->sam)->count());

        for ($i = 0; $i < 6; $i++) {
            $more = $this->shift('2026-10-2'.($i + 2), '10:00', '12:00', area: Area::factory()->create());
            $this->on($more, $this->robin);
            app(ReleaseShifts::class)->one($this->robin, $more, null);
            $this->shift('2026-10-'.(15 + $i), '13:00', '14:00', area: Area::factory()->create());
        }

        $this->assertSame($few, $count());
    }
}
