<?php

namespace Tests\Feature\Scheduling;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\AddRegular;
use App\Actions\Scheduling\CancelShift;
use App\Actions\Scheduling\CreateClosure;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\CreateWeeklySlot;
use App\Actions\Scheduling\PutOnShift;
use App\Actions\Scheduling\RemoveFromShift;
use App\Actions\Scheduling\RestoreShift;
use App\Actions\Scheduling\UpdateShift;
use App\Actions\Scheduling\UpdateWeeklySlot;
use App\ChangeLog\Describe;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Enums\SlotFrequency;
use App\Models\Area;
use App\Models\AreaClosure;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Models\WeeklySlot;
use App\Notifications\ShiftRestoredNotification;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class RestoreShiftTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private Area $frontDesk;

    private Area $hostedEvents;

    private User $robin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutDefer();

        // Wednesday, October 14, 2026, 9am in Detroit.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));
        Organization::factory()->create(['timezone' => self::TIMEZONE, 'weeks_ahead' => 8]);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk']);
        $this->hostedEvents = Area::factory()->create(['name' => 'Hosted events']);
        $this->robin = User::factory()->create(['name' => 'Robin Example']);
    }

    private function coordinator(Role $role = Role::Coordinator, ?Area $area = null): User
    {
        $user = User::factory()->create();
        app(GrantRole::class)->handle(null, $user, $role, $area);
        $this->enableTwoFactor($user);

        return $user;
    }

    /** A Front desk shift on the date, 6:30 to 9:30pm, with Robin on it as a regular. */
    private function robinOn(string $date): Shift
    {
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator(), $this->frontDesk, $date, '18:30', '21:30', 2, 0, null);
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $this->robin->id, 'type' => AssignmentType::Regular]);

        return $shift;
    }

    public function test_restoring_a_shift_cancelled_by_hand(): void
    {
        $shift = $this->robinOn('2026-10-22');
        $coordinator = $this->coordinator();
        app(CancelShift::class)->handle($coordinator, $shift, 'Power outage');
        $this->assertSame('hand', RestoreShift::cancelledBy($shift->fresh()));
        Notification::fake();

        $result = app(RestoreShift::class)->handle($coordinator, $shift);

        $fresh = $shift->fresh();
        $this->assertNull($fresh->cancelled_at);
        $this->assertNull($fresh->cancel_reason);
        $this->assertSame(1, $result['emailed']);
        $this->assertTrue($result['removed']->isEmpty());
        $this->assertSame(1, Assignment::query()->where('shift_id', $shift->id)->count());

        $entry = ChangeLogEntry::query()->forSubject($shift)->ofAction('shift.restored')->sole();
        $this->assertSame($coordinator->id, $entry->actor_id);
        $this->assertSame('Power outage', $entry->changes['cancel_reason']['old']);
        $this->assertNotNull($entry->changes['cancelled_at']['old']);
        $this->assertNull($entry->changes['cancelled_at']['new']);
        $this->assertSame('Brought back the Front desk shift on Thursday, October 22, 6:30 to 9:30pm', Describe::entry($entry->load('subject.area'), $coordinator));

        Notification::assertSentTo($this->robin, ShiftRestoredNotification::class, function (ShiftRestoredNotification $notification) {
            $mail = $notification->toMail($this->robin);
            $this->assertSame('Your Front desk shift is back on', $mail->subject);
            $this->assertSame('The Front desk shift on Thursday, October 22, 6:30 to 9:30pm is back on, and you\'re still on it. If you can\'t make it, let your coordinator know.', $mail->introLines[0]);

            return true;
        });
    }

    public function test_restoring_removes_people_now_on_an_overlapping_shift(): void
    {
        $desk = $this->robinOn('2026-10-22');
        $coordinator = $this->coordinator();
        app(CancelShift::class)->handle($coordinator, $desk, 'Power outage');
        $events = app(CreateOneTimeShift::class)->handle($coordinator, $this->hostedEvents, '2026-10-22', '19:00', '22:00', 2, 0, null);
        app(PutOnShift::class)->handle($coordinator, $events, $this->robin, AssignmentType::Regular);
        Notification::fake();

        $result = app(RestoreShift::class)->handle($coordinator, $desk);

        $this->assertSame(0, $result['emailed']);
        $this->assertSame([$this->robin->id], $result['removed']->pluck('id')->all());
        $this->assertSame(0, Assignment::query()->where('shift_id', $desk->id)->count());
        $this->assertSame(1, Assignment::query()->where('shift_id', $events->id)->count());
        $removal = ChangeLogEntry::query()->forSubject($desk)->ofAction('assignment.removed')->sole();
        $this->assertSame('shift.restored', $removal->details['via']);
        $this->assertSame($this->robin->id, $removal->affected_user_id);
        Notification::assertNothingSent();

        $this->actingAs($coordinator)->get(route('calendar.shift', $desk))->assertOk();
    }

    public function test_the_status_names_the_people_who_were_removed(): void
    {
        $desk = $this->robinOn('2026-10-22');
        $coordinator = $this->coordinator();
        app(CancelShift::class)->handle($coordinator, $desk, 'Power outage');
        $events = app(CreateOneTimeShift::class)->handle($coordinator, $this->hostedEvents, '2026-10-22', '19:00', '22:00', 2, 0, null);
        app(PutOnShift::class)->handle($coordinator, $events, $this->robin, AssignmentType::Regular);

        $this->actingAs($coordinator)->get(route('calendar.shift', [$desk, 'restore' => 1]))->assertOk()
            ->assertSee('Bring it back')->assertSee('Keep it cancelled');
        $this->actingAs($coordinator)->post(route('calendar.shift.restore', $desk))
            ->assertRedirect(route('calendar.shift', $desk))
            ->assertSessionHas('status', 'Brought back the Front desk shift on Thursday, October 22. Robin Example was removed from it, because they\'re now on another shift at that time.');
    }

    public function test_the_panel_offers_the_button_and_says_how_many_are_emailed(): void
    {
        $shift = $this->robinOn('2026-10-22');
        $coordinator = $this->coordinator();
        app(CancelShift::class)->handle($coordinator, $shift, 'Power outage');

        $this->actingAs($coordinator)->get(route('calendar.shift', $shift))->assertOk()->assertSee('Bring this shift back');
        $this->actingAs($coordinator)->get(route('calendar.shift', [$shift, 'restore' => 1]))->assertOk()->assertSee('1 person will be emailed that it&#039;s back on.', false);
        $this->actingAs($coordinator)->post(route('calendar.shift.restore', $shift))
            ->assertSessionHas('status', 'Brought back the Front desk shift on Thursday, October 22. 1 person was emailed.');

        $this->actingAs($this->coordinator(Role::AreaLead, $this->hostedEvents))->post(route('calendar.shift.restore', $shift))->assertForbidden();
    }

    public function test_shifts_cancelled_by_a_closure_or_a_slot_change_explain_instead_of_offering_the_button(): void
    {
        $coordinator = $this->coordinator();

        $byClosure = $this->robinOn('2026-10-22');
        app(CreateClosure::class)->handle($coordinator, [$this->frontDesk->id], '2026-10-22', '2026-10-22', 'Holiday party');
        $byClosure = $byClosure->fresh();
        $this->assertSame('closure', RestoreShift::cancelledBy($byClosure));
        $this->actingAs($coordinator)->get(route('calendar.shift', $byClosure))->assertOk()
            ->assertSeeText('Cancelled by the Holiday party closure. Change the closure to bring it back.')
            ->assertDontSee('Bring this shift back');

        $bySlot = $this->robinOn('2026-10-29');
        app(CancelShift::class)->handle($coordinator, $bySlot, 'The weekly slot changed');
        ChangeLogEntry::record($coordinator, 'shift.cancelled', $bySlot, ['via' => 'slot.updated']);
        $this->assertSame('slot', RestoreShift::cancelledBy($bySlot->fresh()));
        $this->actingAs($coordinator)->get(route('calendar.shift', $bySlot))->assertOk()
            ->assertSee('The weekly slot doesn\'t run on this date any more. Change the slot to bring it back.', false)
            ->assertDontSee('Bring this shift back');

        foreach ([$byClosure, $bySlot] as $shift) {
            $this->actingAs($coordinator)->post(route('calendar.shift.restore', $shift))->assertSessionHasErrors('shift');
            $this->assertNotNull($shift->fresh()->cancelled_at);
        }
    }

    public function test_a_closure_covering_the_date_now_blocks_it(): void
    {
        $shift = $this->robinOn('2026-10-22');
        $coordinator = $this->coordinator();
        app(CancelShift::class)->handle($coordinator, $shift, 'Power outage');
        AreaClosure::create(['area_id' => $this->frontDesk->id, 'starts_on' => '2026-10-21', 'ends_on' => '2026-10-23', 'reason' => 'Renovation']);

        $this->expectExceptionMessage('Front desk is closed that day: Renovation.');
        app(RestoreShift::class)->handle($coordinator, $shift);
    }

    public function test_a_started_or_active_shift_cannot_be_restored(): void
    {
        $coordinator = $this->coordinator();
        $active = $this->robinOn('2026-10-22');

        try {
            app(RestoreShift::class)->handle($coordinator, $active);
            $this->fail('An active shift was restored.');
        } catch (\DomainException $e) {
            $this->assertSame('This shift isn\'t cancelled.', $e->getMessage());
        }

        $started = $this->robinOn('2026-10-15');
        app(CancelShift::class)->handle($coordinator, $started, 'Power outage');
        $this->travelTo(CarbonImmutable::parse('2026-10-15 19:00', self::TIMEZONE));

        try {
            app(RestoreShift::class)->handle($coordinator, $started);
            $this->fail('A started shift was restored.');
        } catch (\DomainException $e) {
            $this->assertSame('This shift has already started.', $e->getMessage());
        }

        $this->assertNotNull($started->fresh()->cancelled_at);
        $this->actingAs($coordinator)->get(route('calendar.shift', $started))->assertOk()->assertDontSee('Bring this shift back');
    }

    public function test_only_people_who_manage_the_area_can_restore(): void
    {
        $shift = $this->robinOn('2026-10-22');
        app(CancelShift::class)->handle($this->coordinator(), $shift, 'Power outage');

        $this->expectException(AuthorizationException::class);
        app(RestoreShift::class)->handle($this->coordinator(Role::AreaLead, $this->hostedEvents), $shift);
    }

    public function test_a_hand_cancelled_date_the_slot_no_longer_runs_on_is_not_offered_and_is_refused(): void
    {
        $coordinator = $this->coordinator();
        $slot = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'start_time' => '18:30', 'end_time' => '21:30', 'valid_from' => '2026-09-03']);
        $shift = $this->robinOn('2026-10-22');
        $shift->forceFill(['weekly_slot_id' => $slot->id])->save();
        app(CancelShift::class)->handle($coordinator, $shift, 'Power outage');
        $slot->forceFill(['valid_until' => '2026-10-15'])->save();
        Notification::fake();

        $this->actingAs($coordinator)->get(route('calendar.shift', $shift))->assertOk()
            ->assertSee('The weekly slot doesn\'t run on this date any more. Change the slot to bring it back.')
            ->assertDontSee('Bring this shift back');
        $this->actingAs($coordinator)->post(route('calendar.shift.restore', $shift))->assertSessionHasErrors('shift');
        $this->assertNotNull($shift->fresh()->cancelled_at);
        Notification::assertNothingSent();
    }

    public function test_a_later_closure_over_a_hand_cancelled_date_shows_the_closure_instead_of_the_button(): void
    {
        $shift = $this->robinOn('2026-10-22');
        $coordinator = $this->coordinator();
        app(CancelShift::class)->handle($coordinator, $shift, 'Power outage');
        AreaClosure::create(['area_id' => $this->frontDesk->id, 'starts_on' => '2026-10-21', 'ends_on' => '2026-10-23', 'reason' => 'Renovation']);

        $this->actingAs($coordinator)->get(route('calendar.shift', $shift))->assertOk()
            ->assertSee('Front desk is closed that day: Renovation.')->assertDontSee('Bring this shift back');
    }

    public function test_the_closure_refusal_uses_the_panel_wording(): void
    {
        $shift = $this->robinOn('2026-10-22');
        $coordinator = $this->coordinator();
        app(CreateClosure::class)->handle($coordinator, [$this->frontDesk->id], '2026-10-22', '2026-10-22', 'Holiday party');

        $this->expectExceptionMessage('Cancelled by the Holiday party closure. Change the closure to bring it back.');
        app(RestoreShift::class)->handle($coordinator, $shift);
    }

    public function test_the_confirm_step_counts_only_people_who_stay_and_names_those_who_will_be_removed(): void
    {
        $desk = $this->robinOn('2026-10-22');
        $casey = User::factory()->create(['name' => 'Casey Example']);
        Assignment::create(['shift_id' => $desk->id, 'user_id' => $casey->id, 'type' => AssignmentType::Regular]);
        $coordinator = $this->coordinator();
        app(CancelShift::class)->handle($coordinator, $desk, 'Power outage');
        $events = app(CreateOneTimeShift::class)->handle($coordinator, $this->hostedEvents, '2026-10-22', '19:00', '22:00', 2, 0, null);
        app(PutOnShift::class)->handle($coordinator, $events, $this->robin, AssignmentType::Regular);

        $this->actingAs($coordinator)->get(route('calendar.shift', [$desk, 'restore' => 1]))->assertOk()
            ->assertSee('1 person will be emailed that it&#039;s back on.', false)
            ->assertSee('Robin Example is on another shift at that time and will be removed from this one.');
    }

    /** A Thursday Front desk slot, 4 to 6pm with 1 shadow spot, from October 15, with its shifts generated and Robin as its regular. */
    private function robinsSlot(): WeeklySlot
    {
        $slot = app(CreateWeeklySlot::class)->handle($this->coordinator(), $this->frontDesk, [
            'weekday' => 4, 'start_time' => '16:00', 'end_time' => '18:00', 'people_needed' => 1, 'shadow_spots' => 1,
            'frequency' => 'weekly', 'valid_from' => '2026-10-15', 'valid_until' => null, 'title' => null, 'is_rotation' => false,
        ]);
        app(AddRegular::class)->handle(null, $slot, $this->robin, '2026-10-15');

        return $slot;
    }

    /** Saves the slot with the values given, keeping the rest. */
    private function changeSlot(WeeklySlot $slot, array $values): void
    {
        $slot = $slot->fresh();
        app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, $values + [
            'weekday' => $slot->weekday, 'start_time' => substr($slot->start_time, 0, 5), 'end_time' => substr($slot->end_time, 0, 5),
            'people_needed' => $slot->people_needed, 'shadow_spots' => $slot->shadow_spots, 'frequency' => $slot->frequency->value,
            'valid_from' => $slot->valid_from->toDateString(), 'valid_until' => $slot->valid_until?->toDateString(),
            'title' => $slot->title, 'is_rotation' => $slot->is_rotation,
        ]);
    }

    public function test_bringing_back_a_shift_applies_the_slots_current_details_and_emails_the_new_time(): void
    {
        $slot = $this->robinsSlot();
        $shift = $slot->shifts()->where('local_date', '2026-10-29')->sole();
        $sam = User::factory()->create(['name' => 'Sam Example']);
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $sam->id, 'type' => AssignmentType::Shadow]);
        $coordinator = $this->coordinator();
        app(CancelShift::class)->handle($coordinator, $shift, 'Power outage');
        // While it's cancelled the slot moves an hour later, needs 2 people and drops its shadow spot.
        $this->changeSlot($slot, ['start_time' => '17:00', 'end_time' => '19:00', 'people_needed' => 2, 'shadow_spots' => 0, 'title' => 'Greeter']);
        $this->assertSame('16:00', $shift->fresh()->starts_at->setTimezone(self::TIMEZONE)->format('H:i'));
        Notification::fake();

        $result = app(RestoreShift::class)->handle($coordinator, $shift);

        $fresh = $shift->fresh();
        $this->assertNull($fresh->cancelled_at);
        $this->assertSame('17:00', $fresh->starts_at->setTimezone(self::TIMEZONE)->format('H:i'));
        $this->assertSame('19:00', $fresh->ends_at->setTimezone(self::TIMEZONE)->format('H:i'));
        $this->assertSame(2, $fresh->people_needed);
        $this->assertSame('Greeter', $fresh->title);
        // Sam is shadowing, so the shift keeps its own shadow spot instead of the slot's none.
        $this->assertSame(1, $fresh->shadow_spots);
        $this->assertSame(2, $result['emailed']);

        $entry = ChangeLogEntry::query()->forSubject($shift)->ofAction('shift.restored')->sole();
        $this->assertSame(1, $entry->changes['people_needed']['old']);
        $this->assertSame(2, $entry->changes['people_needed']['new']);
        $this->assertSame(['old' => null, 'new' => 'Greeter'], $entry->changes['title']);
        $this->assertArrayHasKey('starts_at', $entry->changes);
        $this->assertArrayNotHasKey('shadow_spots', $entry->changes);

        Notification::assertSentTo($this->robin, ShiftRestoredNotification::class, function (ShiftRestoredNotification $notification) {
            $this->assertStringContainsString('Thursday, October 29, 5 to 7pm', $notification->toMail($this->robin)->introLines[0]);

            return true;
        });
    }

    public function test_bringing_back_a_shift_adds_regulars_who_started_while_it_was_cancelled_but_not_turned_off_ones(): void
    {
        $slot = $this->robinsSlot();
        $shift = $slot->shifts()->where('local_date', '2026-10-29')->sole();
        $coordinator = $this->coordinator();
        app(CancelShift::class)->handle($coordinator, $shift, 'Power outage');
        $casey = User::factory()->create(['name' => 'Casey Example']);
        $alex = User::factory()->create(['name' => 'Alex Example']);
        app(AddRegular::class)->handle(null, $slot->fresh(), $casey, '2026-10-22', null, SlotFrequency::Weekly, null, true);
        app(AddRegular::class)->handle(null, $slot->fresh(), $alex, '2026-10-22', null, SlotFrequency::Weekly, null, true);
        $this->assertSame(1, Assignment::query()->where('shift_id', $shift->id)->count());
        // Alex is turned off, and the release that ends their time as a regular hasn't run yet.
        $alex->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($coordinator)->get(route('calendar.shift', [$shift, 'restore' => 1]))->assertOk()
            ->assertSee('2 people will be emailed that it&#039;s back on.', false);
        Notification::fake();

        $result = app(RestoreShift::class)->handle($coordinator, $shift);

        $this->assertSame(2, $result['emailed']);
        $this->assertEqualsCanonicalizing([$this->robin->id, $casey->id], Assignment::query()->where('shift_id', $shift->id)->pluck('user_id')->all());
        $this->assertSame(AssignmentType::Regular, Assignment::query()->where('shift_id', $shift->id)->where('user_id', $casey->id)->sole()->type);
        $entry = ChangeLogEntry::query()->forSubject($shift)->ofAction('shift.restored')->sole();
        $this->assertSame([$casey->id], $entry->details['added_regular_ids']);
        Notification::assertSentTo($casey, ShiftRestoredNotification::class);
        Notification::assertNotSentTo($alex, ShiftRestoredNotification::class);
    }

    public function test_bringing_back_a_shift_leaves_off_a_regular_who_was_taken_off_that_date(): void
    {
        $slot = $this->robinsSlot();
        $shift = $slot->shifts()->where('local_date', '2026-10-29')->sole();
        $coordinator = $this->coordinator();
        // Robin can't make October 29, so they're taken off it, and later the day is cancelled.
        app(RemoveFromShift::class)->one($coordinator, $shift, Assignment::query()->where('shift_id', $shift->id)->sole());
        app(CancelShift::class)->handle($coordinator, $shift->fresh(), 'Power outage');

        $this->actingAs($coordinator)->get(route('calendar.shift', [$shift, 'restore' => 1]))->assertOk()
            ->assertDontSee('will be emailed that it&#039;s back on', false);
        Notification::fake();

        $result = app(RestoreShift::class)->handle($coordinator, $shift);

        $this->assertNull($shift->fresh()->cancelled_at);
        $this->assertSame(0, $result['emailed']);
        $this->assertSame(0, Assignment::query()->where('shift_id', $shift->id)->count());
        $this->assertArrayNotHasKey('added_regular_ids', ChangeLogEntry::query()->forSubject($shift)->ofAction('shift.restored')->sole()->details);
        Notification::assertNotSentTo($this->robin, ShiftRestoredNotification::class);
    }

    public function test_a_hand_edited_shift_comes_back_with_its_own_details(): void
    {
        $slot = $this->robinsSlot();
        $shift = $slot->shifts()->where('local_date', '2026-10-29')->sole();
        $coordinator = $this->coordinator();
        app(UpdateShift::class)->handle($coordinator, $shift, ['people_needed' => 3]);
        app(CancelShift::class)->handle($coordinator, $shift->fresh(), 'Power outage');
        $this->changeSlot($slot, ['start_time' => '17:00', 'end_time' => '19:00']);
        $casey = User::factory()->create(['name' => 'Casey Example']);
        app(AddRegular::class)->handle(null, $slot->fresh(), $casey, '2026-10-22', null, SlotFrequency::Weekly, null, true);
        Notification::fake();

        app(RestoreShift::class)->handle($coordinator, $shift);

        $fresh = $shift->fresh();
        $this->assertNull($fresh->cancelled_at);
        $this->assertSame('16:00', $fresh->starts_at->setTimezone(self::TIMEZONE)->format('H:i'));
        $this->assertSame(3, $fresh->people_needed);
        $this->assertSame([$this->robin->id], Assignment::query()->where('shift_id', $shift->id)->pluck('user_id')->all());
        $this->assertArrayNotHasKey('added_regular_ids', ChangeLogEntry::query()->forSubject($shift)->ofAction('shift.restored')->sole()->details);
    }
}
