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
use App\Enums\TakeHow;
use App\Http\Controllers\Volunteer\HomeController;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\CoverRequest;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Models\WeeklySlot;
use App\Notifications\LoginLinkNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
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
        $this->sam = User::factory()->cleared()->create(['name' => 'Sam Example']);
        $this->robin = User::factory()->cleared()->create(['name' => 'Robin Example']);
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
        // A cancelled shift stays visible, marked, with no "Can't make it" (test_your_cancelled_shifts_stay_on_home_and_all_my_shifts).
        $response->assertSeeInOrder(['Wednesday, October 21', 'Cancelled', 'Closed']);
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
        $response->assertSeeInOrder(['Open shifts you can take', 'Thursday, October 22', 'Needs cover', 'Robin E. can\'t make it', 'Friday, October 16', 'Saturday, October 17', 'Sunday, October 18', 'Sunday, October 25', 'No regular']);
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
        $response->assertDontSee('>Calendar<', false)->assertSee(route('people.index'), false)->assertSee(route('change-log.index'), false)
            ->assertSee(route('account.show'), false)->assertSee(route('account.emails'), false)->assertSee('Log out');

        $volunteer = $this->sees($this->sam);
        $volunteer->assertDontSee('>Calendar<', false)->assertDontSee(route('people.index'), false)->assertDontSee(route('change-log.index'), false)
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
        $response->assertDontSee('October 10')->assertDontSee('October 16');
        $response->assertSeeInOrder(['Friday, December 11', 'Cancelled', 'Closed']);
        $this->assertSame(4, substr_count($response->getContent(), '/release"'));
    }

    public function test_your_cancelled_shifts_stay_on_home_and_all_my_shifts(): void
    {
        $cancelled = $this->shift('2026-10-21');
        $this->on($cancelled, $this->sam);
        app(CancelShift::class)->handle($this->coordinator, $cancelled, 'Power outage');
        $other = $this->shift('2026-10-23');
        app(CancelShift::class)->handle($this->coordinator, $other, 'Holiday');

        foreach (['/', '/my-shifts'] as $url) {
            $response = $this->sees($this->sam, $url);
            $response->assertSeeInOrder(['Wednesday, October 21', 'Cancelled', 'Front desk, 6:30 to 9:30pm', 'Power outage'], false);
            $response->assertDontSee('href="'.url('/shifts/'.$cancelled->id.'/release').'"', false);
            $response->assertDontSee('Holiday');
        }

        $this->assertNotContains($cancelled->id, app(HomeController::class)->openShifts($this->sam)->pluck('id')->all());
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

    /** The response with its HTML entities decoded, so copy reads the same whether the template wrote it or echoed it. */
    private function decoded(TestResponse $response): TestResponse
    {
        return TestResponse::fromBaseResponse(response(html_entity_decode($response->getContent(), ENT_QUOTES | ENT_HTML5), $response->getStatusCode()));
    }

    private function page(User $user, string $url): TestResponse
    {
        return $this->decoded($this->actingAs($user)->get($url)->assertOk());
    }

    /** A Thursday front desk slot, 6:30 to 9:30pm, with its shifts made and Sam as its regular from October 15. */
    private function samsThursdays(): WeeklySlot
    {
        $slot = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'weekday' => 4, 'start_time' => '18:30', 'end_time' => '21:30']);
        app(GenerateShifts::class)->handle(Organization::current());
        app(AddRegular::class)->handle(null, $slot, $this->sam, '2026-10-15');

        return $slot;
    }

    private function thursday(WeeklySlot $slot, string $date): Shift
    {
        return Shift::query()->where('weekly_slot_id', $slot->id)->where('local_date', $date)->sole();
    }

    public function test_the_release_form_asks_which_shifts_with_nothing_picked(): void
    {
        $shift = $this->thursday($this->samsThursdays(), '2026-10-22');

        $response = $this->page($this->sam, route('shifts.release', $shift));
        $response->assertSeeInOrder(['<h1>Can\'t make it</h1>', 'Thursday, October 22', 'Front desk, 6:30 to 9:30pm', 'Which shifts can\'t you make?', 'Just Thursday, October 22', 'Several dates (I\'m away)', 'From', 'To', 'Give up my weekly Thursday slot', 'Starting', 'Note for whoever covers (optional)', 'Release this shift', 'Keep it'], false);
        $response->assertDontSee('checked', false);
        $response->assertSee('type="date"', false)->assertSee('maxlength="500"', false);
        $this->assertSame(1, substr_count($response->getContent(), '<h1'));
    }

    public function test_the_release_form_offers_the_weekly_slot_only_to_its_regular(): void
    {
        $shift = $this->shift('2026-10-22');
        $this->on($shift, $this->sam);

        $this->page($this->sam, route('shifts.release', $shift))
            ->assertSee('Just Thursday, October 22')->assertSee('Several dates (I\'m away)', false)->assertDontSee('Give up my weekly');
    }

    public function test_the_release_form_says_nobody_will_be_asked_when_the_shift_keeps_the_people_it_needs(): void
    {
        $crowded = $this->shift('2026-10-22', needed: 1);
        $this->on($crowded, $this->sam);
        $this->on($crowded, User::factory()->cleared()->create());
        $short = $this->shift('2026-10-23', needed: 1);
        $this->on($short, $this->sam);

        $crowdedPage = $this->page($this->sam, route('shifts.release', $crowded));
        $crowdedPage->assertSee('If you\'re off just Thursday, October 22, Front desk still has the people it needs, so nobody else will be asked.', false)
            ->assertSee('Other volunteers are asked to cover any shift that still needs someone.', false)->assertDontSee('We\'ll ask other volunteers to cover it right away', false);
        $shortPage = $this->page($this->sam, route('shifts.release', $short));
        $shortPage->assertSee('We\'ll ask other volunteers to cover it right away', false)->assertSee('Other volunteers are asked to cover any shift that still needs someone.', false)
            ->assertDontSee('nobody else will be asked', false);

        // With a weekly slot on the form too, the sentence is still only about the one date.
        $slot = $this->samsThursdays();
        $regularShift = $this->thursday($slot, '2026-10-29');
        $this->on($regularShift, User::factory()->cleared()->create());
        $this->page($this->sam, route('shifts.release', $regularShift))
            ->assertSee('Give up my weekly Thursday slot', false)->assertSee('If you\'re off just Thursday, October 29, Front desk still has the people it needs, so nobody else will be asked.', false);
    }

    public function test_the_check_step_says_which_shifts_need_covering(): void
    {
        $crowded = $this->shift('2026-10-22');
        $this->on($crowded, $this->sam);
        $this->on($crowded, User::factory()->cleared()->create());
        $short = $this->shift('2026-10-23');
        $this->on($short, $this->sam);
        $fields = ['scope' => 'range', 'from' => '2026-10-22', 'to' => '2026-10-23'];

        $mixed = $this->decoded($this->actingAs($this->sam)->post(route('shifts.release', $crowded), $fields)->assertOk());
        $mixed->assertSee('We\'ll ask other volunteers to cover one of them.', false)
            ->assertSee('The other one still has the people it needs, so nobody else will be asked.', false)
            ->assertSeeInOrder(['Thursday, October 22', 'Nobody else will be asked', 'Friday, October 23'], false);

        $none = $this->decoded($this->actingAs($this->sam)->post(route('shifts.release', $crowded), ['from' => '2026-10-22', 'to' => '2026-10-22'] + $fields)->assertOk());
        $none->assertSee('Front desk still has the people it needs, so nobody else will be asked.', false)->assertDontSee('We\'ll ask other volunteers', false);

        $all = $this->decoded($this->actingAs($this->sam)->post(route('shifts.release', $short), ['from' => '2026-10-23', 'to' => '2026-10-23'] + $fields)->assertOk());
        $all->assertSee('We\'ll ask other volunteers to cover it.', false)->assertDontSee('nobody else will be asked', false);
    }

    public function test_a_shadow_just_confirms_leaving_the_shadow_spot(): void
    {
        $shift = $this->shift('2026-10-20');
        $this->on($shift, $this->sam, AssignmentType::Shadow);

        $response = $this->page($this->sam, route('shifts.release', $shift));
        $response->assertSee('You\'ll leave your shadow spot on Tuesday, October 20. We\'ll let the coordinators know.', false)
            ->assertSee('Note for whoever covers (optional)')->assertSee('Leave this shadow spot')->assertDontSee('Which shifts');

        $this->actingAs($this->sam)->post(route('shifts.release', $shift), ['note' => 'Sorry, I have a cold.'])->assertRedirect(route('shifts.released'));
        $this->assertFalse($shift->assignments()->where('user_id', $this->sam->id)->exists());

        $this->page($this->sam, route('shifts.released'))
            ->assertSee('You\'re off Tuesday, October 20', false)->assertSee('We\'ve let the coordinators know.', false);
    }

    public function test_releasing_one_shift_shows_you_are_off(): void
    {
        $shift = $this->thursday($this->samsThursdays(), '2026-10-22');
        $this->on($shift, $this->robin, AssignmentType::Shadow);

        $this->actingAs($this->sam)->post(route('shifts.release', $shift), ['scope' => 'one', 'note' => 'The register key is in the top drawer.'])
            ->assertRedirect(route('shifts.released'));

        $this->assertFalse($shift->assignments()->where('user_id', $this->sam->id)->exists());
        $this->assertSame('The register key is in the top drawer.', CoverRequest::query()->sole()->note);

        $response = $this->page($this->sam, route('shifts.released'));
        $response->assertSeeInOrder(['role="status"', '<h1>You\'re off Thursday, October 22</h1>', 'We\'ve asked other volunteers to cover your Front desk shift. We\'ll email you when someone takes it.', 'Robin E. was going to shadow you. We\'ve told them their shadow spot is on hold until someone covers.', 'You stay the Thursday regular.', 'Changed your mind?', 'Your next shifts', 'Thursday, October 15', 'Back to home'], false);
    }

    public function test_releasing_from_a_shift_that_still_has_its_people_says_nobody_was_asked(): void
    {
        $shift = $this->shift('2026-10-22');
        $this->on($shift, $this->sam);
        $this->on($shift, $this->robin);

        $this->actingAs($this->sam)->post(route('shifts.release', $shift), ['scope' => 'one', 'note' => ''])->assertRedirect(route('shifts.released'));

        $this->assertSame(0, CoverRequest::query()->count());
        $response = $this->page($this->sam, route('shifts.released'));
        $response->assertSeeInOrder(['<h1>You\'re off Thursday, October 22</h1>', 'Front desk still has the people it needs, so nobody else was asked.'], false);
        $response->assertDontSee('We\'ve asked')->assertDontSee('Changed your mind?');
    }

    public function test_the_released_page_without_a_release_goes_home(): void
    {
        $this->actingAs($this->sam)->get(route('shifts.released'))->assertRedirect(route('home'));
    }

    public function test_several_dates_are_checked_before_anything_changes(): void
    {
        $slot = $this->samsThursdays();
        $saturday = $this->shift('2026-10-24', '14:30', '20:30');
        $this->on($saturday, $this->sam);
        $fields = ['scope' => 'range', 'from' => '2026-10-15', 'to' => '2026-10-31', 'note' => 'Away on a trip.'];

        $response = $this->decoded($this->actingAs($this->sam)->post(route('shifts.release', $this->thursday($slot, '2026-10-22')), $fields)->assertOk());
        $response->assertSeeInOrder(['<h1>You\'d be off these 4 shifts</h1>', 'Thursday, October 15 to Saturday, October 31', 'Thursday, October 15', 'Front desk, 6:30 to 9:30pm', 'Thursday, October 22', 'Saturday, October 24', 'Front desk, 2:30 to 8:30pm', 'Thursday, October 29', 'You stay the Thursday regular.', 'Your note for whoever covers', 'Away on a trip.', 'Release these 4 shifts', 'Change the dates'], false);
        $response->assertSee('name="confirm" value="1"', false);
        $this->assertSame(0, CoverRequest::query()->count());
        $this->assertSame(4, Assignment::query()->where('user_id', $this->sam->id)->whereIn('shift_id', Shift::query()->whereBetween('local_date', ['2026-10-15', '2026-10-31'])->select('id'))->count());

        $this->actingAs($this->sam)->post(route('shifts.release', $this->thursday($slot, '2026-10-22')), $fields + ['confirm' => 1])->assertRedirect(route('shifts.released'));
        $this->assertSame(4, CoverRequest::query()->count());

        $this->page($this->sam, route('shifts.released'))
            ->assertSeeInOrder(['You\'re off 4 shifts', 'Thursday, October 15', 'Saturday, October 24', 'You stay the Thursday regular.'], false);
    }

    public function test_the_check_step_gives_a_shadow_spot_no_cover_sentence(): void
    {
        $shadow = $this->shift('2026-10-22');
        $this->on($shadow, $this->sam, AssignmentType::Shadow);
        $this->on($shadow, User::factory()->cleared()->create());
        $short = $this->shift('2026-10-23');
        $this->on($short, $this->sam);
        $fields = ['scope' => 'range', 'from' => '2026-10-22', 'to' => '2026-10-23'];

        $response = $this->decoded($this->actingAs($this->sam)->post(route('shifts.release', $short), $fields)->assertOk());
        $response->assertSee('No one is asked to cover a shadow spot.', false)->assertSee('We\'ll ask other volunteers to cover one of them.', false)
            ->assertDontSee('still has the people it needs', false);
    }

    public function test_change_the_dates_goes_back_with_what_was_typed(): void
    {
        $shift = $this->thursday($this->samsThursdays(), '2026-10-22');

        $this->actingAs($this->sam)->post(route('shifts.release', $shift), ['scope' => 'range', 'from' => '2026-10-15', 'to' => '2026-10-31', 'note' => 'Away.', 'change' => 1])
            ->assertRedirect(route('shifts.release', $shift));

        $this->page($this->sam, route('shifts.release', $shift))
            ->assertSee('value="range" checked', false)->assertSee('value="2026-10-31"', false)->assertSee('Away.</textarea>', false);
    }

    public function test_release_refusals_show_on_the_form(): void
    {
        $shift = $this->thursday($this->samsThursdays(), '2026-10-22');
        $post = fn (array $fields) => $this->actingAs($this->sam)->from(route('shifts.release', $shift))->post(route('shifts.release', $shift), $fields);

        $post(['note' => 'x'])->assertRedirect(route('shifts.release', $shift))->assertSessionHasErrors(['scope' => 'Pick which shifts you can\'t make.']);
        $post(['scope' => 'range', 'from' => '2026-10-31', 'to' => '2026-10-15'])->assertSessionHasErrors(['dates' => 'The last date can\'t be before the first date.']);
        $post(['scope' => 'range', 'from' => '2026-12-31', 'to' => '2027-01-02'])->assertSessionHasErrors(['dates' => 'You\'re not on any shifts on those dates.']);
        $post(['scope' => 'one', 'note' => str_repeat('a', 501)])->assertSessionHasErrors(['note' => 'Keep the note under 500 characters.']);
        $post(['scope' => 'slot', 'starting' => '2026-10-01'])->assertSessionHasErrors(['starting' => 'Pick a date from today on.']);

        $this->assertSame(0, CoverRequest::query()->count());

        $this->actingAs($this->sam)->post(route('shifts.release', $shift), ['scope' => 'range', 'from' => '2026-10-31', 'to' => '2026-10-15']);
        $this->page($this->sam, route('shifts.release', $shift))->assertSee('The last date can\'t be before the first date.', false)->assertSee('value="range" checked', false);
    }

    public function test_giving_up_the_weekly_slot_is_checked_then_done(): void
    {
        $slot = $this->samsThursdays();
        $shift = $this->thursday($slot, '2026-10-22');
        $fields = ['scope' => 'slot', 'starting' => '2026-10-29'];

        $this->decoded($this->actingAs($this->sam)->post(route('shifts.release', $shift), $fields)->assertOk())
            ->assertSeeInOrder(['You\'d be off these', 'Thursday, October 29', 'You\'re no longer the Thursday regular from Thursday, October 29.', 'Release these'], false)
            ->assertDontSee('Thursday, October 22</span>', false);
        $this->assertNull($slot->regulars()->sole()->ends_on);

        $this->actingAs($this->sam)->post(route('shifts.release', $shift), $fields + ['confirm' => 1])->assertRedirect(route('shifts.released'));
        $this->assertSame('2026-10-28', $slot->regulars()->sole()->ends_on->toDateString());
        $this->assertTrue($shift->assignments()->where('user_id', $this->sam->id)->exists());

        $this->page($this->sam, route('shifts.released'))->assertSee('You\'re no longer the Thursday regular from Thursday, October 29.', false);
    }

    public function test_a_resubmitted_release_shows_the_refusal_on_home(): void
    {
        $shift = $this->thursday($this->samsThursdays(), '2026-10-22');
        $this->actingAs($this->sam)->post(route('shifts.release', $shift), ['scope' => 'one']);

        $this->actingAs($this->sam)->post(route('shifts.release', $shift), ['scope' => 'one'])
            ->assertRedirect(route('home'))->assertSessionHas('status', 'You\'re not on this shift any more.');
        $this->page($this->sam, route('home'))->assertSee('<p role="status">You\'re not on this shift any more.</p>', false);
        $this->assertSame(1, CoverRequest::query()->count());
    }

    public function test_the_release_form_for_a_shift_you_are_not_on_or_is_cancelled_goes_home(): void
    {
        $shift = $this->shift('2026-10-22');
        $this->actingAs($this->sam)->get(route('shifts.release', $shift))->assertRedirect(route('home'))->assertSessionHas('status', 'You\'re not on this shift any more.');

        $this->on($shift, $this->sam);
        app(CancelShift::class)->handle($this->coordinator, $shift, 'Closed');
        $this->actingAs($this->sam)->get(route('shifts.release', $shift))->assertRedirect(route('home'))->assertSessionHas('status', 'This shift was cancelled.');
    }

    public function test_the_take_page_for_a_cover_request_asks_no_question(): void
    {
        $shift = $this->shift('2026-10-22');
        $this->on($shift, $this->robin);
        $jo = User::factory()->create(['name' => 'Jo Trainee']);
        $this->on($shift, $jo, AssignmentType::Shadow);
        app(ReleaseShifts::class)->one($this->robin, $shift, 'The register key is in the top drawer.');

        $response = $this->page($this->sam, route('shifts.take', $shift));
        $response->assertSeeInOrder(['<h1>Can you cover this one?</h1>', 'Thursday, October 22', 'Front desk, 6:30 to 9:30pm', 'Robin E. can\'t make it. You\'d take this date only.', 'Shadowing: Jo', 'Note from Robin E.', 'The register key is in the top drawer.', 'Take this shift', 'Not this time'], false);
        $response->assertDontSee('type="radio"', false)->assertSee('name="how" value="date"', false)->assertDontSee('Trainee');
    }

    public function test_the_take_page_for_a_slot_with_no_regular_offers_three_choices_none_picked(): void
    {
        WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'weekday' => 0, 'start_time' => '11:30', 'end_time' => '14:30']);
        app(GenerateShifts::class)->handle(Organization::current());
        $shift = Shift::query()->where('local_date', '2026-10-18')->sole();

        $response = $this->page($this->sam, route('shifts.take', $shift));
        $response->assertSeeInOrder(['Sunday, October 18', 'Front desk, 11:30am to 2:30pm', 'Needs 1 person', 'This weekly slot has no regular right now.', 'How long do you want it?', 'Just Sunday, October 18', 'Every Sunday', 'You\'d become this slot\'s regular.', 'Every other Sunday', 'Starting Sunday, October 18. Someone else can take the weeks in between.', 'Take this shift'], false);
        $response->assertDontSee('checked', false);
        $this->assertSame(3, substr_count($response->getContent(), 'type="radio"'));
        $this->assertSame(1, substr_count($response->getContent(), '<h1'));

        $this->actingAs($this->sam)->from(route('shifts.take', $shift))->post(route('shifts.take', $shift))->assertSessionHasErrors(['how' => 'Pick how long you want it.']);

        $this->actingAs($this->sam)->post(route('shifts.take', $shift), ['how' => 'weekly'])->assertRedirect(route('shifts.taken', $shift));
        $this->page($this->sam, route('shifts.taken', $shift))->assertSee('It\'s yours. Thanks, Sam!', false)->assertSee('You\'re now the Sunday regular, starting Sunday, October 18.', false);
        $this->assertTrue(Shift::query()->where('local_date', '2026-10-25')->sole()->assignments()->where('user_id', $this->sam->id)->exists());
    }

    public function test_the_take_page_for_a_plain_open_shift_has_one_choice(): void
    {
        $shift = $this->shift('2026-10-22');

        $this->page($this->sam, route('shifts.take', $shift))
            ->assertSee('<h1>Can you take this one?</h1>', false)->assertSee('You\'d take this date only.', false)
            ->assertDontSee('type="radio"', false)->assertSee('name="how" value="date"', false);
    }

    public function test_taking_a_cover_request_says_its_yours(): void
    {
        $lead = User::factory()->create(['name' => 'Alex Lead']);
        app(GrantRole::class)->handle(null, $lead, Role::AreaLead, $this->frontDesk);
        $this->frontDesk->update(['lead_name' => 'Shift lead']);
        $shift = $this->shift('2026-10-22');
        $this->on($shift, $this->robin);
        app(ReleaseShifts::class)->one($this->robin, $shift, null);

        $this->actingAs($this->sam)->post(route('shifts.take', $shift), ['how' => 'date'])->assertRedirect(route('shifts.taken', $shift));
        $this->assertTrue($shift->assignments()->where('user_id', $this->sam->id)->exists());

        $response = $this->page($this->sam, route('shifts.taken', $shift));
        $response->assertSeeInOrder(['role="status"', '<h1>It\'s yours. Thanks, Sam!</h1>', 'Thursday, October 22', 'Front desk, 6:30 to 9:30pm', 'We\'ve emailed you the details, and told Robin E. and Alex L. (Shift lead).', 'Can\'t make it after all? It\'s under "Your next shifts" on your home page.', 'Back to home'], false);
    }

    public function test_the_taken_page_needs_you_on_the_shift(): void
    {
        $shift = $this->shift('2026-10-22');

        $this->actingAs($this->sam)->get(route('shifts.taken', $shift))->assertRedirect(route('shifts.take', $shift));
    }

    public function test_a_filled_shift_says_someone_already_took_it_and_lists_others(): void
    {
        $shift = $this->shift('2026-10-22');
        $other = $this->shift('2026-10-23', '15:30', '18:30');
        $this->on($shift, $this->robin);

        $response = $this->page($this->sam, route('shifts.take', $shift));
        $response->assertSeeInOrder(['role="status"', '<h1>Someone already took this one</h1>', 'Front desk on Thursday, October 22 is covered. Thanks for offering!', 'Other open shifts you can take', 'Friday, October 23', 'Back to home'], false);
        $response->assertSee(route('shifts.take', $other), false)->assertDontSee('href="'.route('shifts.take', $shift).'"', false);
    }

    public function test_a_stale_take_shows_someone_already_took_it(): void
    {
        $shift = $this->shift('2026-10-22');
        $this->on($shift, $this->robin);
        app(ReleaseShifts::class)->one($this->robin, $shift, null);
        $this->page($this->sam, route('shifts.take', $shift))->assertSee('Can you cover this one?');

        $jo = User::factory()->cleared()->create(['name' => 'Jo Example']);
        app(TakeShift::class)->handle($jo, $shift, TakeHow::JustThisDate);

        $this->actingAs($this->sam)->post(route('shifts.take', $shift), ['how' => 'date'])->assertRedirect(route('shifts.take', $shift));
        $this->actingAs($this->sam)->get(route('shifts.take', $shift))->assertSee('Someone already took this one');
        $this->assertFalse($shift->assignments()->where('user_id', $this->sam->id)->exists());
    }

    public function test_a_cancelled_or_started_shift_says_so_on_the_take_page(): void
    {
        $cancelled = $this->shift('2026-10-22');
        app(CancelShift::class)->handle($this->coordinator, $cancelled, 'Closed');
        $this->page($this->sam, route('shifts.take', $cancelled))->assertSee('This shift was cancelled.')->assertSee('href="'.route('home').'"', false)->assertDontSee('Take this shift');

        $started = Shift::create(['area_id' => $this->frontDesk->id, 'local_date' => '2026-10-14', 'starts_at' => '2026-10-14 12:00:00', 'ends_at' => '2026-10-14 16:00:00', 'people_needed' => 1, 'shadow_spots' => 0]);
        $this->page($this->sam, route('shifts.take', $started))->assertSee('This shift has already started.')->assertDontSee('Take this shift');
    }

    public function test_other_take_refusals_show_on_the_page(): void
    {
        $mine = $this->shift('2026-10-22', '18:00', '20:00');
        $this->on($mine, $this->sam);
        $overlap = $this->shift('2026-10-22', '19:00', '21:00', area: Area::factory()->create(['name' => 'Hosted events']));

        $this->page($this->sam, route('shifts.take', $overlap))->assertSee('You\'re already on Front desk, Thursday, October 22, 6 to 8pm.', false)->assertDontSee('Take this shift');
        $shadowed = $this->shift('2026-10-23');
        $this->on($shadowed, $this->sam, AssignmentType::Shadow);
        $this->page($this->sam, route('shifts.take', $shadowed))->assertSee('You\'re shadowing this shift.', false)->assertDontSee('Take this shift');
    }

    public function test_home_cards_link_to_the_named_routes(): void
    {
        $shift = $this->shift('2026-10-22');
        $this->on($shift, $this->sam);
        $open = $this->shift('2026-10-23');

        $this->sees($this->sam)->assertSee('href="'.route('shifts.release', $shift).'"', false)->assertSee('href="'.route('shifts.take', $open).'"', false);
    }

    public function test_a_guest_taking_a_shift_logs_in_by_link_and_lands_back_on_it(): void
    {
        Notification::fake();
        $shift = $this->shift('2026-10-22');
        $this->sam->update(['email' => 'sam@example.org']);

        $this->get(route('shifts.take', $shift))->assertRedirect(route('login'));
        $this->get(route('login'))->assertSee('Log in to take this shift. Once you\'re in, we\'ll take you straight back to it.', false);
        $this->post(route('login-link.request'), ['email' => 'sam@example.org']);

        $url = null;
        Notification::assertSentTo($this->sam, LoginLinkNotification::class, function (LoginLinkNotification $notification) use (&$url) {
            $url = $notification->url;

            return true;
        });

        $this->flushSession();
        $this->post(route('login-link.consume', basename(parse_url($url, PHP_URL_PATH))))->assertRedirect(route('shifts.take', $shift));
        $this->get(route('shifts.take', $shift))->assertOk()->assertSee('Can you take this one?');
    }

    public function test_a_given_up_slot_offers_every_week_with_the_cover_request(): void
    {
        $slot = $this->samsThursdays();
        $regular = $slot->regulars()->sole();
        app(ReleaseShifts::class)->slot($this->sam, $regular, '2026-10-22', 'Moving away, thanks all.');
        $shift = $this->thursday($slot, '2026-10-22');

        $response = $this->page($this->robin, route('shifts.take', $shift));
        $response->assertSeeInOrder(['Thursday, October 22', 'Sam E. can\'t make it.', 'This weekly slot has no regular right now.', 'Note from Sam E.', 'Moving away, thanks all.', 'How long do you want it?', 'Just Thursday, October 22', 'Every Thursday', 'Every other Thursday'], false);
        $response->assertDontSee('You\'d take this date only.', false)->assertDontSee('checked', false);
    }

    public function test_the_releaser_can_take_their_shift_back(): void
    {
        $shift = $this->shift('2026-10-22');
        $this->on($shift, $this->robin);
        app(ReleaseShifts::class)->one($this->robin, $shift, 'Note for the others.');

        $this->page($this->robin, route('shifts.take', $shift))
            ->assertSee('<h1>Can you make it after all?</h1>', false)->assertSee('You released this shift. You\'d take this date back.', false)->assertDontSee('Note for the others.');

        $this->actingAs($this->robin)->post(route('shifts.take', $shift), ['how' => 'date'])->assertRedirect(route('shifts.taken', $shift));
        $this->page($this->robin, route('shifts.taken', $shift))->assertSee('It\'s yours. Thanks, Robin!', false)->assertSee('We\'ve emailed you the details.', false);
    }

    public function test_a_repeated_take_goes_to_its_yours(): void
    {
        $shift = $this->shift('2026-10-22');
        $this->on($shift, $this->robin);
        app(ReleaseShifts::class)->one($this->robin, $shift, null);

        $this->actingAs($this->sam)->post(route('shifts.take', $shift), ['how' => 'date'])->assertRedirect(route('shifts.taken', $shift));
        $this->actingAs($this->sam)->post(route('shifts.take', $shift), ['how' => 'date'])->assertRedirect(route('shifts.taken', $shift))->assertSessionHasNoErrors();
        $this->actingAs($this->sam)->get(route('shifts.take', $shift))->assertRedirect(route('shifts.taken', $shift));
        $this->assertSame(1, $shift->assignments()->where('user_id', $this->sam->id)->count());
    }

    public function test_a_release_that_loses_a_race_goes_home(): void
    {
        // The policy said yes, but the place was gone by the time the form loaded it, as when another tab released it in between.
        Gate::before(fn () => true);
        $shift = $this->shift('2026-10-22');

        $this->actingAs($this->sam)->get(route('shifts.release', $shift))->assertRedirect(route('home'))->assertSessionHas('status', 'You\'re not on this shift any more.');
        $this->actingAs($this->sam)->post(route('shifts.release', $shift), ['scope' => 'one'])->assertRedirect(route('home'))->assertSessionHas('status', 'You\'re not on this shift any more.');
    }

    public function test_release_form_errors_are_announced(): void
    {
        $shift = $this->thursday($this->samsThursdays(), '2026-10-22');

        foreach ([['note' => ''], ['scope' => 'range', 'from' => '2026-10-31', 'to' => '2026-10-15'], ['scope' => 'slot', 'starting' => '2026-10-01'], ['scope' => 'one', 'note' => str_repeat('a', 501)]] as $fields) {
            $this->actingAs($this->sam)->post(route('shifts.release', $shift), $fields);
            $this->assertMatchesRegularExpression('/<span class="field-error" id="\w+-error" role="alert">/', $this->actingAs($this->sam)->get(route('shifts.release', $shift))->getContent(), json_encode($fields));
        }
    }

    public function test_a_cancelled_shift_you_are_on_never_says_its_yours(): void
    {
        $shift = $this->shift('2026-10-22');
        $this->on($shift, $this->sam);
        app(CancelShift::class)->handle($this->coordinator, $shift, 'Closed');

        $this->page($this->sam, route('shifts.take', $shift))->assertSee('This shift was cancelled.')->assertDontSee('It\'s yours', false);
        $this->actingAs($this->sam)->get(route('shifts.taken', $shift))->assertRedirect(route('shifts.take', $shift));
    }
}
