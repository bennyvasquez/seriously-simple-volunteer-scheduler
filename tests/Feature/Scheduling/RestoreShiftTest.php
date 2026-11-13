<?php

namespace Tests\Feature\Scheduling;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\CancelShift;
use App\Actions\Scheduling\CreateClosure;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\PutOnShift;
use App\Actions\Scheduling\RestoreShift;
use App\ChangeLog\Describe;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Models\Area;
use App\Models\AreaClosure;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
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
            ->assertSee('Cancelled by the Holiday party closure. Change the closure to bring it back.')
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
}
