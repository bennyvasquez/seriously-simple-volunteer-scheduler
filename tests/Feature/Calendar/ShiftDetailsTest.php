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

    private function shiftOn(string $date, string $start, string $end, int $headcount = 1, int $shadowSpots = 0, ?Area $area = null): Shift
    {
        return app(CreateOneTimeShift::class)->handle($this->coordinator(), $area ?? $this->frontDesk, $date, $start, $end, $headcount, $shadowSpots, null);
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
        $shift = $this->shiftOn('2026-10-22', '18:30', '21:30', headcount: 1);
        $robin = User::factory()->create(['name' => 'Robin']);

        app(PutOnShift::class)->handle($this->coordinator(), $shift, $robin, AssignmentType::Regular);

        $this->assertSame(0, $shift->fresh()->openPlaces());
        $this->assertTrue(ChangeLogEntry::query()->about($robin)->ofAction('assignment.added')->exists());
        Notification::assertSentTo($robin, PutOnShiftNotification::class);
    }

    public function test_putting_someone_on_a_full_or_shared_shift_is_refused(): void
    {
        $shift = $this->shiftOn('2026-10-22', '18:30', '21:30', headcount: 1);
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
        $shift = $this->shiftOn('2026-10-22', '18:30', '21:30', headcount: 1);
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
            'This shift is full. Raise the number of people needed first.',
            "There's no shadow spot left on this shift.",
        ], array_slice($messages, 0, 3));
        $this->assertCount(4, $messages);
    }

    public function test_a_shadow_goes_in_a_shadow_spot_and_not_in_a_place(): void
    {
        $shift = $this->shiftOn('2026-10-22', '18:30', '21:30', headcount: 1, shadowSpots: 1);

        app(PutOnShift::class)->handle($this->coordinator(), $shift, User::factory()->create(), AssignmentType::Shadow);

        $this->assertSame(1, $shift->fresh()->openPlaces());
    }

    public function test_a_started_or_cancelled_shift_takes_nobody(): void
    {
        $started = $this->shiftOn('2026-10-14', '08:00', '12:00');
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
        $started = $this->shiftOn('2026-10-14', '08:00', '12:00');

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
        $this->assertStringContainsString("You're on the Front desk shift on Thursday, October 22, 6:30 to 9:30pm. If you can't make it, release it from your shifts.", implode(' ', $mail->introLines));
    }

    public function test_the_panel_shows_people_and_places(): void
    {
        $shift = $this->shiftWithPerson(name: 'Sam', shadow: 'Robin');

        $this->actingAs($this->coordinator())->get(route('calendar.shift', $shift))
            ->assertOk()->assertSee('Sam')->assertSee('Shadowing')->assertSee('1 of 1 place filled')->assertSee('1 of 1 shadow spot taken')
            ->assertSee('One time')->assertSee('Thursday, October 22')->assertSee('Put someone on it')->assertSee('Cancel this date');
    }

    public function test_the_panel_describes_a_repeating_slot_and_its_regulars(): void
    {
        $slot = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'start_time' => '18:30', 'end_time' => '21:30', 'valid_from' => '2026-09-03']);
        $sam = User::factory()->create(['name' => 'Sam']);
        SlotRegular::create(['weekly_slot_id' => $slot->id, 'user_id' => $sam->id, 'starts_on' => '2026-09-03']);
        $shift = Shift::create([
            'area_id' => $this->frontDesk->id, 'weekly_slot_id' => $slot->id, 'local_date' => '2026-10-22',
            'starts_at' => CarbonImmutable::parse('2026-10-22 18:30', self::TIMEZONE)->utc(), 'ends_at' => CarbonImmutable::parse('2026-10-22 21:30', self::TIMEZONE)->utc(),
            'headcount' => 1, 'shadow_spots' => 0,
        ]);
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $sam->id, 'type' => AssignmentType::Regular]);

        $this->actingAs($this->coordinator())->get(route('calendar.shift', $shift))
            ->assertOk()->assertSee('Repeats every Thursday, since Sep 3. No end date.')->assertSee('Regular, every week');
    }

    public function test_a_started_shift_is_read_only(): void
    {
        $shift = $this->shiftOn('2026-10-14', '08:00', '12:00');

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
}
