<?php

namespace Tests\Feature\Calendar;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\CancelShift;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\PutOnShift;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Models\WeeklySlot;
use App\Notifications\PutOnShiftNotification;
use App\Notifications\ShiftCancelledNotification;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class ShiftDetailsTest extends TestCase
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

    private function coordinator(Role $role = Role::Coordinator, ?Area $area = null): User
    {
        $user = User::factory()->create();
        app(GrantRole::class)->handle(null, $user, $role, $area);
        $this->enableTwoFactor($user);

        return $user;
    }

    private function shiftOn(string $date, string $start, string $end, int $peopleNeeded = 1, int $shadowSpots = 0, ?Area $area = null): Shift
    {
        return app(CreateOneTimeShift::class)->handle($this->coordinator(), $area ?? $this->frontDesk, $date, $start, $end, $peopleNeeded, $shadowSpots, null);
    }

    /** A shift from 8am to noon today, which started an hour ago. CreateOneTimeShift refuses a start time that has passed, so it's made earlier in the morning. */
    private function startedShift(): Shift
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 07:00', self::TIMEZONE));
        $shift = $this->shiftOn('2026-10-14', '08:00', '12:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));

        return $shift;
    }

    private function shiftWithPerson(string $name = 'Sam', ?string $shadow = null): Shift
    {
        $shift = $this->shiftOn('2026-10-22', '18:30', '21:30', shadowSpots: $shadow === null ? 0 : 1);
        Assignment::create(['shift_id' => $shift->id, 'user_id' => User::factory()->create(['name' => $name])->id, 'type' => AssignmentType::Regular]);

        if ($shadow !== null) {
            Assignment::create(['shift_id' => $shift->id, 'user_id' => User::factory()->create(['name' => $shadow])->id, 'type' => AssignmentType::Shadow]);
        }

        return $shift;
    }

    public function test_putting_someone_on_a_shift_logs_and_emails_them(): void
    {
        Notification::fake();
        $shift = $this->shiftOn('2026-10-22', '18:30', '21:30', peopleNeeded: 1);
        $robin = User::factory()->create(['name' => 'Robin']);

        app(PutOnShift::class)->handle($this->coordinator(), $shift, $robin, AssignmentType::Regular);

        $this->assertSame(0, $shift->fresh()->openPlaces());
        $this->assertTrue(ChangeLogEntry::query()->about($robin)->ofAction('assignment.added')->exists());
        Notification::assertSentTo($robin, PutOnShiftNotification::class);
    }

    public function test_putting_someone_on_a_full_or_shared_shift_is_refused(): void
    {
        $shift = $this->shiftOn('2026-10-22', '18:30', '21:30', peopleNeeded: 1);
        $robin = User::factory()->create();
        app(PutOnShift::class)->handle($this->coordinator(), $shift, $robin, AssignmentType::Regular);

        foreach ([$robin, User::factory()->create()] as $person) {
            try {
                app(PutOnShift::class)->handle($this->coordinator(), $shift, $person, AssignmentType::Regular);
                $this->fail('A second assignment was allowed.');
            } catch (DomainException) {
            }
        }

        $this->assertSame(1, $shift->assignments()->count());
    }

    public function test_the_refusals_say_why(): void
    {
        $shift = $this->shiftOn('2026-10-22', '18:30', '21:30', peopleNeeded: 1);
        $robin = User::factory()->create(['name' => 'Robin Example']);
        $action = app(PutOnShift::class);
        $coordinator = $this->coordinator();
        $action->handle($coordinator, $shift, $robin, AssignmentType::Regular);

        $messages = [];
        foreach ([[$robin, AssignmentType::Regular], [User::factory()->create(), AssignmentType::Regular], [User::factory()->create(), AssignmentType::Shadow], [User::factory()->create(['deactivated_at' => now()]), AssignmentType::Regular]] as [$person, $type]) {
            try {
                $action->handle($coordinator, $shift, $person, $type);
            } catch (DomainException $e) {
                $messages[] = $e->getMessage();
            }
        }

        $this->assertSame([
            'Robin is already on this shift.',
            'This shift is full.',
            "There's no shadow spot left on this shift.",
        ], array_slice($messages, 0, 3));
        $this->assertCount(4, $messages);
    }

    public function test_putting_someone_on_a_shift_that_overlaps_another_of_theirs_is_refused(): void
    {
        $woodShop = Area::factory()->create(['name' => 'Wood shop']);
        $robin = User::factory()->create(['name' => 'Robin Example']);
        $coordinator = $this->coordinator();
        app(PutOnShift::class)->handle($coordinator, $this->shiftOn('2026-10-22', '18:00', '21:00', area: $woodShop), $robin, AssignmentType::Regular);
        $cancelled = $this->shiftOn('2026-10-23', '18:00', '21:00', area: $woodShop);
        app(PutOnShift::class)->handle($coordinator, $cancelled, $robin, AssignmentType::Regular);
        app(CancelShift::class)->handle($coordinator, $cancelled, 'Power outage');

        try {
            app(PutOnShift::class)->handle($coordinator, $this->shiftOn('2026-10-22', '18:30', '21:30', shadowSpots: 1), $robin, AssignmentType::Shadow);
            $this->fail('An overlapping shift was allowed.');
        } catch (DomainException $e) {
            $this->assertSame('Robin is already on Wood shop, Thursday, October 22, 6 to 9pm.', $e->getMessage());
        }

        // Back to back is fine, and so is a cancelled shift at the same time.
        app(PutOnShift::class)->handle($coordinator, $this->shiftOn('2026-10-22', '21:00', '22:00'), $robin, AssignmentType::Regular);
        app(PutOnShift::class)->handle($coordinator, $this->shiftOn('2026-10-23', '18:30', '21:30'), $robin, AssignmentType::Regular);
        $this->assertSame(4, Assignment::query()->where('user_id', $robin->id)->count());
    }

    public function test_putting_someone_on_a_shift_is_logged_against_the_shift_with_the_assignment(): void
    {
        $shift = $this->shiftOn('2026-10-22', '18:30', '21:30', shadowSpots: 1);
        $robin = User::factory()->create();
        $coordinator = $this->coordinator();

        $assignment = app(PutOnShift::class)->handle($coordinator, $shift, $robin, AssignmentType::Shadow);

        $entry = ChangeLogEntry::query()->forSubject($shift)->ofAction('assignment.added')->sole();
        $this->assertSame($coordinator->id, $entry->actor_id);
        $this->assertSame($robin->id, $entry->affected_user_id);
        $this->assertSame(['assignment_id' => $assignment->id, 'type' => 'shadow'], $entry->details);
        $this->assertSame(['old' => null, 'new' => $robin->id], $entry->changes['user_id']);
        $this->assertSame(['old' => null, 'new' => 'shadow'], $entry->changes['type']);
    }

    public function test_a_shadow_goes_in_a_shadow_spot_and_not_in_a_place(): void
    {
        $shift = $this->shiftOn('2026-10-22', '18:30', '21:30', peopleNeeded: 1, shadowSpots: 1);

        app(PutOnShift::class)->handle($this->coordinator(), $shift, User::factory()->create(), AssignmentType::Shadow);

        $this->assertSame(1, $shift->fresh()->openPlaces());
    }

    public function test_a_started_or_cancelled_shift_takes_nobody(): void
    {
        $started = $this->startedShift();
        $cancelled = $this->shiftOn('2026-10-22', '18:30', '21:30');
        app(CancelShift::class)->handle($this->coordinator(), $cancelled, 'Power outage');

        foreach ([$started, $cancelled] as $shift) {
            try {
                app(PutOnShift::class)->handle($this->coordinator(), $shift, User::factory()->create(), AssignmentType::Regular);
                $this->fail('Someone was put on a shift that is over for sign-ups.');
            } catch (DomainException) {
            }
        }

        $this->assertSame(0, Assignment::query()->count());
    }

    public function test_only_someone_who_manages_the_area_can_put_people_on_or_cancel(): void
    {
        $shift = $this->shiftOn('2026-10-22', '18:30', '21:30');
        $lead = $this->coordinator(Role::AreaLead, $this->hostedEvents);

        try {
            app(PutOnShift::class)->handle($lead, $shift, User::factory()->create(), AssignmentType::Regular);
            $this->fail('A lead of another area put someone on the shift.');
        } catch (AuthorizationException) {
        }

        $this->expectException(AuthorizationException::class);
        app(CancelShift::class)->handle($lead, $shift, 'Nope');
    }

    public function test_cancelling_a_date_keeps_the_shift_and_tells_people(): void
    {
        Notification::fake();
        $shift = $this->shiftWithPerson();

        app(CancelShift::class)->handle($this->coordinator(), $shift, 'Power outage');

        $this->assertNotNull($shift->fresh()->cancelled_at);
        $this->assertSame('Power outage', $shift->fresh()->cancel_reason);
        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);
        $this->assertTrue(ChangeLogEntry::query()->forSubject($shift)->ofAction('shift.cancelled')->exists());
        Notification::assertSentTo($shift->assignments()->first()->user, ShiftCancelledNotification::class);
    }

    public function test_a_shift_that_has_started_cannot_be_cancelled(): void
    {
        $started = $this->startedShift();

        $this->expectException(DomainException::class);
        app(CancelShift::class)->handle($this->coordinator(), $started, 'Too late');
    }

    public function test_the_emails_use_us_dates_and_times(): void
    {
        $shift = $this->shiftWithPerson();
        $person = $shift->assignments()->first()->user;
        $mail = (new ShiftCancelledNotification(collect([$shift->load('area')]), 'Power outage'))->toMail($person);
        $text = implode(' ', $mail->introLines);

        $this->assertStringContainsString('The Front desk shift on Thursday, October 22, 6:30 to 9:30pm is cancelled: Power outage. You don\'t need to do anything.', $text);

        $mail = (new PutOnShiftNotification($shift))->toMail($person);
        $this->assertStringContainsString("You're on the Front desk shift on Thursday, October 22, 6:30 to 9:30pm. If you can't make it, let your coordinator know.", implode(' ', $mail->introLines));
    }

    public function test_a_reason_that_ends_with_a_period_is_not_doubled(): void
    {
        $shift = $this->shiftWithPerson()->load('area');
        $person = $shift->assignments()->first()->user;

        $one = implode(' ', (new ShiftCancelledNotification(collect([$shift]), 'Power outage.'))->toMail($person)->introLines);
        $several = implode(' ', (new ShiftCancelledNotification(collect([$shift, $shift]), 'Is the heat back on?'))->toMail($person)->introLines);

        $this->assertStringContainsString('is cancelled: Power outage. You don\'t need to do anything.', $one);
        $this->assertStringContainsString('These shifts are cancelled: Is the heat back on? ', $several.' ');
        $this->assertStringNotContainsString('..', $one);
        $this->assertStringNotContainsString('?.', $several);
    }

    public function test_the_panel_shows_people_and_places(): void
    {
        $shift = $this->shiftWithPerson(name: 'Sam', shadow: 'Robin');

        $this->actingAs($this->coordinator())->get(route('calendar.shift', $shift))
            ->assertOk()->assertSee('Sam')->assertSee('Shadowing')->assertSee('1 of 1 place filled')->assertSee('1 of 1 shadow spot taken')
            ->assertSee('One time')->assertSee('Thursday, October 22')->assertSee('Cancel this date');
    }

    public function test_the_panel_describes_a_repeating_slot_and_its_regulars(): void
    {
        $slot = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'start_time' => '18:30', 'end_time' => '21:30', 'valid_from' => '2026-09-03']);
        $sam = User::factory()->create(['name' => 'Sam']);
        SlotRegular::create(['weekly_slot_id' => $slot->id, 'user_id' => $sam->id, 'starts_on' => '2026-09-03']);
        $shift = Shift::create([
            'area_id' => $this->frontDesk->id, 'weekly_slot_id' => $slot->id, 'local_date' => '2026-10-22',
            'starts_at' => CarbonImmutable::parse('2026-10-22 18:30', self::TIMEZONE)->utc(), 'ends_at' => CarbonImmutable::parse('2026-10-22 21:30', self::TIMEZONE)->utc(),
            'people_needed' => 1, 'shadow_spots' => 0,
        ]);
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $sam->id, 'type' => AssignmentType::Regular]);

        $this->actingAs($this->coordinator())->get(route('calendar.shift', $shift))
            ->assertOk()->assertSee('Repeats every Thursday, since Sep 3. No end date.')->assertSee('Regular, every week');
    }

    public function test_a_started_shift_is_read_only(): void
    {
        $shift = $this->startedShift();

        $this->actingAs($this->coordinator())->get(route('calendar.shift', $shift))
            ->assertOk()->assertDontSee('Put someone on it')->assertDontSee('Cancel this date');
    }

    public function test_a_cancelled_shift_shows_why_and_is_read_only(): void
    {
        $shift = $this->shiftOn('2026-10-22', '18:30', '21:30');
        app(CancelShift::class)->handle($this->coordinator(), $shift, 'Power outage');

        $this->actingAs($this->coordinator())->get(route('calendar.shift', $shift))
            ->assertOk()->assertSee('Cancelled: Power outage')->assertDontSee('Put someone on it')->assertDontSee('Cancel this date');
    }

    public function test_searching_lists_active_matches_and_a_match_can_be_put_on(): void
    {
        Notification::fake();
        $shift = $this->shiftOn('2026-10-22', '18:30', '21:30');
        $robin = User::factory()->create(['name' => 'Robin Example', 'email' => 'robin@example.org']);
        User::factory()->create(['name' => 'Robin Gone', 'deactivated_at' => now()]);
        $coordinator = $this->coordinator();

        $this->actingAs($coordinator)->get(route('calendar.shift', [$shift, 'q' => 'robin']))
            ->assertOk()->assertSee('Robin Example')->assertDontSee('Robin Gone');

        $this->actingAs($coordinator)->post(route('calendar.shift.assign', $shift), ['person' => $robin->id, 'type' => 'regular', 'areas' => [$this->frontDesk->id]])
            ->assertRedirect(route('calendar.shift', [$shift, 'areas' => [$this->frontDesk->id]]));

        $this->assertSame(0, $shift->fresh()->openPlaces());
        Notification::assertSentTo($robin, PutOnShiftNotification::class);
    }

    public function test_a_refusal_comes_back_on_the_panel(): void
    {
        $shift = $this->shiftWithPerson();
        $robin = User::factory()->create();

        $this->actingAs($this->coordinator())->from(route('calendar.shift', $shift))->post(route('calendar.shift.assign', $shift), ['person' => $robin->id, 'type' => 'regular'])
            ->assertRedirect(route('calendar.shift', $shift))->assertSessionHasErrors('shift');
    }

    public function test_cancelling_from_the_panel_needs_a_reason_and_logs_it(): void
    {
        Notification::fake();
        $shift = $this->shiftWithPerson();
        $coordinator = $this->coordinator();

        $this->actingAs($coordinator)->get(route('calendar.shift', [$shift, 'cancel' => 1]))->assertOk()->assertSee('Why is it cancelled?');
        $this->actingAs($coordinator)->post(route('calendar.shift.cancel', $shift), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertNull($shift->fresh()->cancelled_at);

        $this->actingAs($coordinator)->post(route('calendar.shift.cancel', $shift), ['reason' => 'Power outage'])->assertRedirect();
        $this->assertNotNull($shift->fresh()->cancelled_at);
    }

    public function test_a_lead_of_another_area_cannot_put_people_on_or_cancel_from_the_panel(): void
    {
        $shift = $this->shiftWithPerson();
        $lead = $this->coordinator(Role::AreaLead, $this->hostedEvents);

        $this->actingAs($lead)->post(route('calendar.shift.assign', $shift), ['person' => User::factory()->create()->id, 'type' => 'regular'])->assertForbidden();
        $this->actingAs($lead)->post(route('calendar.shift.cancel', $shift), ['reason' => 'No'])->assertForbidden();
        $this->actingAs($lead)->get(route('calendar.shift', $shift))->assertOk()->assertDontSee('Put someone on it');
    }

    public function test_the_week_links_to_the_shift(): void
    {
        $shift = $this->shiftOn('2026-10-22', '18:30', '21:30');

        $this->actingAs($this->coordinator())->get(route('calendar.week', ['date' => '2026-10-22']))
            ->assertOk()->assertSee(route('calendar.shift', $shift), false);
    }

    public function test_a_reason_over_200_characters_is_refused_by_the_action(): void
    {
        $shift = $this->shiftOn('2026-10-22', '18:30', '21:30');

        try {
            app(CancelShift::class)->handle($this->coordinator(), $shift, str_repeat('a', 201));
            $this->fail('A 201 character reason was accepted.');
        } catch (DomainException $e) {
            $this->assertSame('Keep it to 200 characters or fewer.', $e->getMessage());
        }

        $this->assertNull($shift->fresh()->cancelled_at);
    }

    public function test_cancelling_returns_how_many_active_people_were_emailed(): void
    {
        Notification::fake();
        $shift = $this->shiftWithPerson(name: 'Sam', shadow: 'Robin');
        User::query()->where('name', 'Robin')->update(['deactivated_at' => now()]);

        $count = app(CancelShift::class)->handle($this->coordinator(), $shift, 'Power outage');

        $this->assertSame(1, $count);
        $this->actingAs($this->coordinator())->post(route('calendar.shift.cancel', $this->shiftWithPerson('Pat')), ['reason' => 'Snow'])
            ->assertSessionHas('status', fn (string $status) => str_contains($status, '1 person was emailed.'));
    }

    public function test_put_someone_on_it_is_hidden_when_nothing_is_left_to_fill(): void
    {
        $shift = $this->shiftWithPerson();

        $this->actingAs($this->coordinator())->get(route('calendar.shift', $shift))
            ->assertOk()->assertDontSee('Put someone on it')->assertSee('Cancel this date');
    }

    public function test_a_lead_searches_by_name_only_and_sees_emails_only_of_people_they_may_see(): void
    {
        $shift = $this->shiftOn('2026-10-22', '18:30', '21:30');
        User::factory()->create(['name' => 'Robin Example', 'email' => 'robin.secret@example.org']);
        $lead = $this->coordinator(Role::AreaLead, $this->frontDesk);

        $this->actingAs($lead)->get(route('calendar.shift', [$shift, 'q' => 'robin']))
            ->assertOk()->assertSee('Robin E.')->assertDontSee('Robin Example')->assertDontSee('Robin Ex')->assertDontSee('robin.secret@example.org');

        $this->actingAs($lead)->get(route('calendar.shift', [$shift, 'q' => 'secret']))
            ->assertOk()->assertDontSee('Robin E.');

        $this->actingAs($this->coordinator())->get(route('calendar.shift', [$shift, 'q' => 'secret']))
            ->assertOk()->assertSee('Robin Example')->assertSee('robin.secret@example.org');
    }

    public function test_the_panel_shows_a_full_name_only_for_people_the_viewer_may_see(): void
    {
        $shift = $this->shiftWithPerson(name: 'Sam Example');
        $outsideLead = $this->coordinator(Role::AreaLead, $this->hostedEvents);
        $areaLead = $this->coordinator(Role::AreaLead, $this->frontDesk);

        // A first name and last initial, as in the person search, so two people called Sam can be told apart.
        $this->actingAs($outsideLead)->get(route('calendar.shift', $shift))->assertOk()->assertSee('Sam E.')->assertDontSee('Sam Example');
        $this->actingAs($areaLead)->get(route('calendar.shift', $shift))->assertOk()->assertSee('Sam Example');
    }

    public function test_user_first_name(): void
    {
        $this->assertSame('Robin', User::factory()->make(['name' => ' Robin Example '])->firstName());
        $this->assertSame('Robin E.', User::factory()->make(['name' => 'Robin Q. example'])->shortName());
        $this->assertSame('Robin', User::factory()->make(['name' => 'Robin'])->shortName());
    }

    public function test_the_cancel_hint_counts_only_active_people(): void
    {
        $shift = $this->shiftWithPerson(name: 'Sam', shadow: 'Robin');
        User::query()->where('name', 'Robin')->update(['deactivated_at' => now()]);

        $this->actingAs($this->coordinator())->get(route('calendar.shift', [$shift, 'cancel' => 1]))
            ->assertOk()->assertSee('1 person will be emailed this reason.');
    }
}
