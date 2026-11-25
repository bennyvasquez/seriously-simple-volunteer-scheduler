<?php

namespace Tests\Feature\Calendar;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\CancelShift;
use App\Actions\Scheduling\CreateClosure;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\CreateWeeklySlot;
use App\Actions\Scheduling\GenerateShifts;
use App\Actions\Scheduling\UpdateWeeklySlot;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Models\Area;
use App\Models\AreaClosure;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Models\WeeklySlot;
use App\Notifications\ShiftCancelledNotification;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class ClosureTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private Area $frontDesk;

    private Area $hostedEvents;

    protected function setUp(): void
    {
        parent::setUp();

        // The emails are deferred until after the response; calling an action directly has no response, so run them right away.
        $this->withoutDefer();

        // Wednesday, October 14, 2026, 9am in Detroit.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));
        Organization::factory()->create(['timezone' => self::TIMEZONE, 'weeks_ahead' => 8]);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk']);
        $this->hostedEvents = Area::factory()->create(['name' => 'Hosted events']);
    }

    private function withRole(Role $role, ?Area $area = null): User
    {
        $user = User::factory()->create();
        app(GrantRole::class)->handle(null, $user, $role, $area);
        $this->enableTwoFactor($user);

        return $user;
    }

    private function coordinator(): User
    {
        return $this->withRole(Role::Coordinator);
    }

    private function shiftOn(string $date, string $start = '15:30', string $end = '18:30', ?Area $area = null, int $shadowSpots = 0): Shift
    {
        return app(CreateOneTimeShift::class)->handle($this->coordinator(), $area ?? $this->frontDesk, $date, $start, $end, 1, $shadowSpots, null);
    }

    private function shiftWithPerson(User $person, string $date = '2026-11-26', string $start = '15:30', string $end = '18:30', ?Area $area = null): Shift
    {
        $shift = $this->shiftOn($date, $start, $end, $area);
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $person->id, 'type' => AssignmentType::Regular]);

        return $shift;
    }

    public function test_a_closure_cancels_existing_shifts_and_tells_each_person_once(): void
    {
        Notification::fake();
        $morgan = User::factory()->create();
        $thu = $this->shiftWithPerson($morgan, date: '2026-11-26');
        $fri = $this->shiftWithPerson($morgan, date: '2026-11-27');

        app(CreateClosure::class)->handle($this->coordinator(), [$this->frontDesk->id], '2026-11-26', '2026-11-29', 'Thanksgiving');

        $this->assertNotNull($thu->fresh()->cancelled_at);
        $this->assertNotNull($fri->fresh()->cancelled_at);
        Notification::assertSentToTimes($morgan, ShiftCancelledNotification::class, 1);
        app(GenerateShifts::class)->handle(Organization::current());
        $this->assertSame(0, Shift::query()->active()->whereBetween('local_date', ['2026-11-26', '2026-11-29'])->count());
    }

    public function test_the_one_email_lists_every_cancelled_shift_with_the_reason(): void
    {
        Notification::fake();
        $morgan = User::factory()->create();
        $this->shiftWithPerson($morgan, date: '2026-11-26');
        $this->shiftWithPerson($morgan, date: '2026-11-27', area: $this->hostedEvents);

        app(CreateClosure::class)->handle($this->coordinator(), [$this->frontDesk->id, $this->hostedEvents->id], '2026-11-26', '2026-11-29', 'Thanksgiving');

        Notification::assertSentTo($morgan, ShiftCancelledNotification::class, function (ShiftCancelledNotification $notification) {
            return $notification->reason === 'Thanksgiving' && $notification->shifts === [
                'Front desk shift on Thursday, November 26, 3:30 to 6:30pm',
                'Hosted events shift on Friday, November 27, 3:30 to 6:30pm',
            ];
        });
    }

    public function test_a_closure_records_the_closure_and_each_shift_it_cancels(): void
    {
        Notification::fake();
        $thu = $this->shiftWithPerson(User::factory()->create(), date: '2026-11-26');
        $open = $this->shiftOn('2026-11-28');
        $coordinator = $this->coordinator();

        $closures = app(CreateClosure::class)->handle($coordinator, [$this->frontDesk->id, $this->hostedEvents->id], '2026-11-26', '2026-11-29', 'Thanksgiving');

        $this->assertCount(2, $closures);
        $frontDeskClosure = $closures->firstWhere('area_id', $this->frontDesk->id);
        $this->assertSame($frontDeskClosure->id, $thu->fresh()->area_closure_id);
        $this->assertSame('Thanksgiving', $thu->fresh()->cancel_reason);

        $entry = ChangeLogEntry::query()->forSubject($frontDeskClosure)->ofAction('closure.created')->sole();
        $this->assertSame($coordinator->id, $entry->actor_id);
        $this->assertEqualsCanonicalizing([$thu->id, $open->id], $entry->details['cancelled_shifts']);
        $this->assertSame([], ChangeLogEntry::query()->forSubject($closures->firstWhere('area_id', $this->hostedEvents->id))->ofAction('closure.created')->sole()->details['cancelled_shifts']);

        foreach ([$thu, $open] as $shift) {
            $cancelled = ChangeLogEntry::query()->forSubject($shift)->ofAction('shift.cancelled')->sole();
            $this->assertSame('closure.created', $cancelled->details['via']);
            $this->assertSame($frontDeskClosure->id, $cancelled->details['area_closure_id']);
            $this->assertNull($cancelled->changes['cancelled_at']['old']);
            $this->assertSame('Thanksgiving', $cancelled->changes['cancel_reason']['new']);
        }
    }

    public function test_a_closure_leaves_started_shifts_other_areas_and_other_dates_alone_and_skips_inactive_people(): void
    {
        Notification::fake();
        $started = $this->shiftWithPerson(User::factory()->create(), date: '2026-11-26', start: '15:30', end: '18:30');
        $other = $this->shiftWithPerson(User::factory()->create(), date: '2026-11-27', area: $this->hostedEvents);
        $after = $this->shiftWithPerson(User::factory()->create(), date: '2026-11-30');
        $gone = User::factory()->create(['deactivated_at' => now()]);
        $later = $this->shiftWithPerson($gone, date: '2026-11-27');
        $this->travelTo(CarbonImmutable::parse('2026-11-26 16:00', self::TIMEZONE));

        app(CreateClosure::class)->handle($this->coordinator(), [$this->frontDesk->id], '2026-11-26', '2026-11-29', 'Thanksgiving');

        $this->assertNull($started->fresh()->cancelled_at);
        $this->assertNull($other->fresh()->cancelled_at);
        $this->assertNull($after->fresh()->cancelled_at);
        $this->assertNotNull($later->fresh()->cancelled_at);
        $this->assertSame(4, Shift::query()->count());
        Notification::assertNothingSent();
    }

    public function test_an_overlapping_closure_is_allowed_but_never_cancels_or_emails_twice(): void
    {
        Notification::fake();
        $morgan = User::factory()->create();
        $thu = $this->shiftWithPerson($morgan, date: '2026-11-26');
        $sat = $this->shiftWithPerson($morgan, date: '2026-11-28');
        $first = app(CreateClosure::class)->handle($this->coordinator(), [$this->frontDesk->id], '2026-11-26', '2026-11-26', 'Thanksgiving')->sole();

        $second = app(CreateClosure::class)->handle($this->coordinator(), [$this->frontDesk->id], '2026-11-26', '2026-11-29', 'Holiday weekend')->sole();

        $this->assertSame(2, AreaClosure::query()->count());
        $this->assertSame($first->id, $thu->fresh()->area_closure_id);
        $this->assertSame('Thanksgiving', $thu->fresh()->cancel_reason);
        $this->assertSame($second->id, $sat->fresh()->area_closure_id);
        $this->assertSame(1, ChangeLogEntry::query()->forSubject($thu)->ofAction('shift.cancelled')->count());
        Notification::assertSentToTimes($morgan, ShiftCancelledNotification::class, 2);
        Notification::assertSentTo($morgan, ShiftCancelledNotification::class, fn (ShiftCancelledNotification $notification) => $notification->reason === 'Holiday weekend' && count($notification->shifts) === 1);
    }

    public function test_a_shift_cancelled_by_hand_is_not_cancelled_again(): void
    {
        Notification::fake();
        $thu = $this->shiftWithPerson(User::factory()->create(), date: '2026-11-26');
        app(CancelShift::class)->handle($this->coordinator(), $thu, 'Power outage');

        app(CreateClosure::class)->handle($this->coordinator(), [$this->frontDesk->id], '2026-11-26', '2026-11-29', 'Thanksgiving');

        $this->assertSame('Power outage', $thu->fresh()->cancel_reason);
        $this->assertNull($thu->fresh()->area_closure_id);
        Notification::assertCount(1);
    }

    public function test_a_slot_edit_never_brings_back_a_date_a_closure_cancelled(): void
    {
        Notification::fake();
        $slot = app(CreateWeeklySlot::class)->handle($this->coordinator(), $this->frontDesk, [
            'weekday' => 4, 'start_time' => '15:30', 'end_time' => '18:30', 'people_needed' => 1, 'shadow_spots' => 0,
            'frequency' => 'weekly', 'valid_from' => '2026-10-15', 'valid_until' => null, 'title' => null, 'is_rotation' => false,
        ]);
        app(CreateClosure::class)->handle($this->coordinator(), [$this->frontDesk->id], '2026-11-26', '2026-11-26', 'Thanksgiving');
        $attributes = ['weekday' => 4, 'start_time' => '15:30', 'end_time' => '18:30', 'people_needed' => 1, 'shadow_spots' => 0, 'valid_from' => '2026-10-15', 'valid_until' => null, 'title' => null, 'is_rotation' => false];

        app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['frequency' => 'every_other_week'] + $attributes);
        app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot->fresh(), ['frequency' => 'weekly'] + $attributes);

        $thanksgiving = $slot->shifts()->where('local_date', '2026-11-26')->sole();
        $this->assertNotNull($thanksgiving->cancelled_at);
        $this->assertSame('Thanksgiving', $thanksgiving->cancel_reason);
    }

    public function test_the_action_checks_each_area_and_the_values(): void
    {
        $lead = $this->withRole(Role::AreaLead, $this->frontDesk);

        try {
            app(CreateClosure::class)->handle($lead, [$this->frontDesk->id], '2026-11-26', '2026-11-29', 'Thanksgiving');
            $this->fail('An area lead added a closure.');
        } catch (AuthorizationException) {
        }

        $messages = [];
        foreach ([
            [[], '2026-11-26', '2026-11-29', 'Thanksgiving'],
            [[$this->frontDesk->id], '2026-11-29', '2026-11-26', 'Thanksgiving'],
            [[$this->frontDesk->id], '2026-10-01', '2026-10-13', 'Thanksgiving'],
            [[$this->frontDesk->id], '2026-11-26', '2026-11-29', '  '],
            [[$this->frontDesk->id], '2026-11-26', '2026-11-29', str_repeat('a', 201)],
            [[$this->frontDesk->id, 999999], '2026-11-26', '2026-11-29', 'Thanksgiving'],
        ] as [$areas, $from, $to, $reason]) {
            try {
                app(CreateClosure::class)->handle($this->coordinator(), $areas, $from, $to, $reason);
            } catch (DomainException $e) {
                $messages[] = $e->getMessage();
            }
        }

        $this->assertSame([
            'Pick at least one area.',
            'The last day closed has to be on or after the first day.',
            'Pick days from today on.',
            'Say why it\'s closed, so volunteers know.',
            'Keep it to 200 characters or fewer.',
            'One of those areas no longer exists.',
        ], $messages);
        $this->assertSame(0, AreaClosure::query()->count());
    }

    public function test_leads_cannot_add_closures(): void
    {
        $lead = $this->withRole(Role::AreaLead, $this->frontDesk);

        $this->actingAs($lead)->get(route('calendar.closures.create'))->assertForbidden();
        $this->actingAs($lead)->post(route('calendar.closures.store'), ['area' => [$this->frontDesk->id], 'from' => '2026-11-26', 'to' => '2026-11-29', 'reason' => 'Thanksgiving'])->assertForbidden();
        $this->assertSame(0, AreaClosure::query()->count());
    }

    public function test_the_form_lists_what_it_will_cancel(): void
    {
        $this->shiftWithPerson(User::factory()->create(['name' => 'Morgan Example']), date: '2026-11-26');
        $sam = $this->shiftOn('2026-11-26', '18:30', '21:30', shadowSpots: 1);
        Assignment::create(['shift_id' => $sam->id, 'user_id' => User::factory()->create(['name' => 'Sam Example'])->id, 'type' => AssignmentType::Regular]);
        Assignment::create(['shift_id' => $sam->id, 'user_id' => User::factory()->create(['name' => 'Robin Example'])->id, 'type' => AssignmentType::Shadow]);
        $this->shiftOn('2026-11-28', '14:30', '20:30');
        $this->shiftOn('2026-11-27', area: $this->hostedEvents);
        $this->shiftOn('2026-11-30');

        $this->actingAs($this->coordinator())->get(route('calendar.closures.create', ['area' => $this->frontDesk->id, 'from' => '2026-11-26', 'to' => '2026-11-29']))
            ->assertOk()
            ->assertSee('Add a closure')
            ->assertSee('What this cancels')
            ->assertSeeInOrder(['Thu Nov 26, 3:30 to 6:30pm', 'Morgan Example', 'Thu Nov 26, 6:30 to 9:30pm', 'Sam Example, Robin Example shadowing', 'Sat Nov 28, 2:30 to 8:30pm', 'Open'])
            ->assertDontSee('Fri Nov 27')
            ->assertDontSee('Mon Nov 30')
            ->assertSee('We\'ll email the 3 people on these shifts that they\'re cancelled.')
            ->assertSee('Close Front desk and cancel 3 shifts')
            ->assertSee('Check what this cancels')
            // The save button sends refresh empty, so a form the browser restored with refresh set still saves.
            ->assertSee('class="btn btn-primary" name="refresh" value=""', false)
            ->assertDontSee('WHEN');
    }

    public function test_the_days_never_reload_the_form_and_the_check_button_is_always_there(): void
    {
        $html = $this->actingAs($this->coordinator())->get(route('calendar.closures.create'))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/<input type="date"[^>]*data-refreshes-form/', $html);
        $this->assertMatchesRegularExpression('/<input type="checkbox" name="area\[\]"[^>]*data-refreshes-form/', $html);
        $this->assertStringContainsString('<button type="submit" class="btn" name="refresh" value="1">Check what this cancels</button>', $html);
        $this->assertStringNotContainsString('<noscript>', $html);
    }

    public function test_the_form_names_the_area_of_each_shift_when_closing_several(): void
    {
        $this->shiftOn('2026-11-26');
        $this->shiftOn('2026-11-27', area: $this->hostedEvents);

        $this->actingAs($this->coordinator())->get(route('calendar.closures.create', ['area' => [$this->frontDesk->id, $this->hostedEvents->id], 'from' => '2026-11-26', 'to' => '2026-11-29']))
            ->assertOk()
            ->assertSeeInOrder(['Thu Nov 26, 3:30 to 6:30pm, Front desk', 'Fri Nov 27, 3:30 to 6:30pm, Hosted events'])
            ->assertSee('Nobody is on these shifts, so nobody is emailed.')
            ->assertSee('Close Front desk and Hosted events and cancel 2 shifts');
    }

    public function test_the_form_without_days_asks_for_them(): void
    {
        $this->actingAs($this->coordinator())->get(route('calendar.closures.create'))
            ->assertOk()
            ->assertSee('Pick the areas and days to see what this cancels.');
    }

    public function test_saving_the_form_closes_and_says_what_it_did(): void
    {
        Notification::fake();
        $this->shiftWithPerson(User::factory()->create(), date: '2026-11-26');
        $this->shiftOn('2026-11-27');

        $this->actingAs($this->coordinator())->post(route('calendar.closures.store'), ['area' => [$this->frontDesk->id], 'from' => '2026-11-26', 'to' => '2026-11-29', 'reason' => 'Thanksgiving'])
            ->assertRedirect(route('calendar.week', ['date' => '2026-11-26']))
            ->assertSessionHas('status', 'Closed Front desk from Thursday, November 26 to Sunday, November 29. Cancelled 2 shifts and emailed 1 person.');

        $this->assertSame(1, AreaClosure::query()->count());
        $this->assertSame(0, Shift::query()->active()->count());
    }

    public function test_the_check_button_refreshes_the_list_and_keeps_the_reason(): void
    {
        $this->actingAs($this->coordinator())->post(route('calendar.closures.store'), ['refresh' => '1', 'area' => [$this->frontDesk->id], 'from' => '2026-11-26', 'to' => '2026-11-29', 'reason' => 'Thanksgiving'])
            ->assertRedirect(route('calendar.closures.create', ['area' => [$this->frontDesk->id], 'from' => '2026-11-26', 'to' => '2026-11-29']))
            ->assertSessionHasInput('reason', 'Thanksgiving');

        $this->assertSame(0, AreaClosure::query()->count());
    }

    public function test_saving_the_form_without_a_reason_says_why(): void
    {
        $this->actingAs($this->coordinator())->from(route('calendar.closures.create'))->post(route('calendar.closures.store'), ['area' => [$this->frontDesk->id], 'from' => '2026-11-26', 'to' => '2026-11-29', 'reason' => ''])
            ->assertSessionHasErrors(['reason' => 'Say why it\'s closed, so volunteers know.']);

        $this->assertSame(0, AreaClosure::query()->count());
    }

    public function test_the_week_shows_a_closure_as_an_all_day_bar(): void
    {
        AreaClosure::query()->create(['area_id' => $this->frontDesk->id, 'starts_on' => '2026-11-26', 'ends_on' => '2026-11-27', 'reason' => 'Thanksgiving']);

        $response = $this->actingAs($this->coordinator())->get(route('calendar.week', ['date' => '2026-11-26']))->assertOk();

        $response->assertSee('Closed: Thanksgiving (Front desk)');
        // Once per day in the grid and once per day in the phone list.
        $this->assertSame(4, substr_count($response->getContent(), 'Closed: Thanksgiving (Front desk)'));
    }

    public function test_the_month_links_to_closures_only_for_coordinators(): void
    {
        $this->actingAs($this->coordinator())->get(route('calendar.month', ['month' => '2026-11']))
            ->assertSee('href="'.route('calendar.closures.create').'"', false);
        $this->actingAs($this->withRole(Role::AreaLead, $this->frontDesk))->get(route('calendar.month', ['month' => '2026-11']))
            ->assertDontSee('Add a closure');
    }

    public function test_the_week_sidebar_links_to_closures_only_for_people_who_can_close_an_area(): void
    {
        $this->actingAs($this->coordinator())->get(route('calendar.week', ['date' => '2026-11-26']))
            ->assertSee('href="'.route('calendar.closures.create').'"', false)->assertSee('Add a closure');
        $this->actingAs($this->coordinator())->get(route('calendar.day', ['date' => '2026-11-26']))
            ->assertSee('Add a closure');
        $this->actingAs($this->withRole(Role::AreaLead, $this->frontDesk))->get(route('calendar.week', ['date' => '2026-11-26']))
            ->assertDontSee('Add a closure');
    }

    public function test_the_month_cancelled_count_is_not_styled_like_a_link(): void
    {
        $css = file_get_contents(public_path('css/app.css'));

        $this->assertMatchesRegularExpression('/\.cancelled-count\{[^}]*\}/', $css);
        preg_match('/\.cancelled-count\{([^}]*)\}/', $css, $rule);
        $this->assertStringNotContainsString('underline', $rule[1]);
    }

    public function test_a_closure_saved_while_the_generator_runs_still_keeps_its_days_empty(): void
    {
        WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'valid_from' => '2026-10-15']);
        $inserted = false;

        // Right after the generator reads the area's closures, a coordinator saves one for Thursday, October 22, as if it committed in between.
        DB::listen(function (QueryExecuted $query) use (&$inserted) {
            if (! $inserted && str_contains($query->sql, 'area_closures')) {
                $inserted = true;
                DB::table('area_closures')->insert(['area_id' => $this->frontDesk->id, 'starts_on' => '2026-10-22', 'ends_on' => '2026-10-22', 'reason' => 'Holiday', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        app(GenerateShifts::class)->handle(Organization::current());

        $this->assertTrue($inserted);
        $this->assertFalse(Shift::query()->where('local_date', '2026-10-22')->exists());
        $this->assertTrue(Shift::query()->where('local_date', '2026-10-29')->exists());
    }

    public function test_a_closure_that_started_before_today_is_stored_from_today(): void
    {
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-27 09:00', self::TIMEZONE));

        $closure = app(CreateClosure::class)->handle($this->coordinator(), [$this->frontDesk->id], '2026-11-26', '2026-11-29', 'Thanksgiving')->sole();
        $this->assertSame('2026-11-27', $closure->starts_on->toDateString());

        $this->actingAs($this->coordinator())->post(route('calendar.closures.store'), ['area' => [$this->hostedEvents->id], 'from' => '2026-11-26', 'to' => '2026-11-29', 'reason' => 'Thanksgiving'])
            ->assertRedirect(route('calendar.week', ['date' => '2026-11-27']))
            ->assertSessionHas('status', 'Hosted events is closed from today, Friday, November 27, through Sunday, November 29. No shifts were on the calendar for those days.');
        $this->assertSame('2026-11-27', AreaClosure::query()->where('area_id', $this->hostedEvents->id)->sole()->starts_on->toDateString());
    }

    public function test_a_closure_across_the_end_of_daylight_saving_time_shows_local_times_and_cancels_each_day(): void
    {
        Notification::fake();
        // Daylight saving time ends in Detroit on Sunday, November 1, 2026.
        $shifts = collect(['2026-10-31', '2026-11-01', '2026-11-02'])->map(fn (string $date) => $this->shiftOn($date, '18:30', '21:30'));

        $this->actingAs($this->coordinator())->get(route('calendar.closures.create', ['area' => $this->frontDesk->id, 'from' => '2026-10-31', 'to' => '2026-11-02']))
            ->assertOk()
            ->assertSeeInOrder(['Sat Oct 31, 6:30 to 9:30pm', 'Sun Nov 1, 6:30 to 9:30pm', 'Mon Nov 2, 6:30 to 9:30pm'])
            ->assertSee('Close Front desk and cancel 3 shifts');

        app(CreateClosure::class)->handle($this->coordinator(), [$this->frontDesk->id], '2026-10-31', '2026-11-02', 'Repairs');

        $shifts->each(fn (Shift $shift) => $this->assertNotNull($shift->fresh()->cancelled_at));
    }
}
