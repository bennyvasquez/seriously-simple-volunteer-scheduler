<?php

namespace Tests\Feature\Scheduling;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\CreateClosure;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\CreateWeeklySlot;
use App\Actions\Scheduling\RemoveClosure;
use App\Actions\Scheduling\UpdateClosure;
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
use App\Models\WeeklySlot;
use App\Notifications\ShiftCancelledNotification;
use App\Notifications\ShiftRestoredNotification;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class UpdateClosureTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private Area $frontDesk;

    private User $robin;

    private User $casey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutDefer();

        // Wednesday, October 14, 2026, 9am in Detroit.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));
        Organization::factory()->create(['timezone' => self::TIMEZONE, 'weeks_ahead' => 8]);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk']);
        $this->robin = User::factory()->create(['name' => 'Robin Example', 'email' => 'robin@example.org']);
        $this->casey = User::factory()->create(['name' => 'Casey Example', 'email' => 'casey@example.org']);
    }

    private function coordinator(): User
    {
        return $this->withRole(Role::Coordinator);
    }

    private function withRole(Role $role, ?Area $area = null): User
    {
        $user = User::factory()->create();
        app(GrantRole::class)->handle(null, $user, $role, $area);
        $this->enableTwoFactor($user);

        return $user;
    }

    /** A Front desk shift on the date, 3:30 to 6:30pm, with the people given on it as regulars. */
    private function shiftOn(string $date, User ...$people): Shift
    {
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator(), $this->frontDesk, $date, '15:30', '18:30', 2, 0, null);

        foreach ($people as $person) {
            Assignment::create(['shift_id' => $shift->id, 'user_id' => $person->id, 'type' => AssignmentType::Regular]);
        }

        return $shift;
    }

    private function close(string $from, string $to, string $reason = 'Thanksgiving'): AreaClosure
    {
        return app(CreateClosure::class)->handle($this->coordinator(), [$this->frontDesk->id], $from, $to, $reason)->first();
    }

    /** A Thursday Front desk slot, 3:30 to 6:30pm, from October 15. */
    private function thursdaySlot(): WeeklySlot
    {
        return app(CreateWeeklySlot::class)->handle($this->coordinator(), $this->frontDesk, [
            'weekday' => 4, 'start_time' => '15:30', 'end_time' => '18:30', 'people_needed' => 1, 'shadow_spots' => 0,
            'frequency' => 'weekly', 'valid_from' => '2026-10-15', 'valid_until' => null, 'title' => null, 'is_rotation' => false,
        ]);
    }

    public function test_shrinking_a_closure_brings_back_the_days_it_no_longer_covers(): void
    {
        $wed = $this->shiftOn('2026-11-25', $this->robin);
        $fri = $this->shiftOn('2026-11-27', $this->robin);
        $sat = $this->shiftOn('2026-11-28', $this->robin, $this->casey);
        $closure = $this->close('2026-11-25', '2026-11-28');
        // Made while the closure stood, so Thursday, November 26 has no shift until the closure no longer covers it.
        $slot = $this->thursdaySlot();
        $this->assertSame(0, $slot->shifts()->where('local_date', '2026-11-26')->count());
        $coordinator = $this->coordinator();
        Notification::fake();

        $saved = app(UpdateClosure::class)->handle($coordinator, $closure, '2026-11-25', '2026-11-25', 'Thanksgiving');

        $this->assertSame('2026-11-25', $saved->ends_on->toDateString());
        $this->assertNotNull($wed->fresh()->cancelled_at);
        foreach ([$fri, $sat] as $shift) {
            $fresh = $shift->fresh();
            $this->assertNull($fresh->cancelled_at);
            $this->assertNull($fresh->cancel_reason);
            $this->assertNull($fresh->area_closure_id);
            $restored = ChangeLogEntry::query()->forSubject($shift)->ofAction('shift.restored')->sole();
            $this->assertSame($coordinator->id, $restored->actor_id);
            $this->assertSame('closure.updated', $restored->details['via']);
            $this->assertSame($closure->id, $restored->details['area_closure_id']);
        }
        $this->assertNull($slot->shifts()->where('local_date', '2026-11-26')->sole()->cancelled_at);

        $entry = ChangeLogEntry::query()->forSubject($closure)->ofAction('closure.updated')->sole();
        $this->assertSame(['old' => '2026-11-28', 'new' => '2026-11-25'], $entry->changes['ends_on']);
        $this->assertSame('Changed the Front desk closure to Nov 25', Describe::entry($entry->load('subject.area'), $coordinator));

        // The generator made Thursday's shift after the change, logged once with who caused it.
        $generated = ChangeLogEntry::query()->ofAction('shifts.generated')->where('actor_id', $coordinator->id)->sole();
        $this->assertSame('closure.updated', $generated->details['via']);
        $this->assertSame($this->frontDesk->id, $generated->details['area_id']);
        $this->assertSame(1, $generated->details['created']);

        // One email each, listing every shift that came back.
        Notification::assertSentToTimes($this->robin, ShiftRestoredNotification::class, 1);
        Notification::assertSentTo($this->robin, ShiftRestoredNotification::class, function (ShiftRestoredNotification $notification) {
            $mail = $notification->toMail($this->robin);
            $this->assertSame('Your Front desk shifts are back on', $mail->subject);

            return $notification->shifts === ['Front desk shift on Friday, November 27, 3:30 to 6:30pm', 'Front desk shift on Saturday, November 28, 3:30 to 6:30pm'];
        });
        Notification::assertSentToTimes($this->casey, ShiftRestoredNotification::class, 1);
        Notification::assertNotSentTo([$this->robin, $this->casey], ShiftCancelledNotification::class);
    }

    public function test_shrinking_a_closure_leaves_days_another_closure_covers_cancelled(): void
    {
        $fri = $this->shiftOn('2026-11-27', $this->robin);
        $sat = $this->shiftOn('2026-11-28', $this->robin);
        $a = $this->close('2026-11-25', '2026-11-28');
        $b = $this->close('2026-11-27', '2026-11-29', 'Black Friday');
        $coordinator = $this->coordinator();
        Notification::fake();

        app(UpdateClosure::class)->handle($coordinator, $a, '2026-11-25', '2026-11-26', 'Thanksgiving');

        foreach ([$fri, $sat] as $shift) {
            $fresh = $shift->fresh();
            $this->assertNotNull($fresh->cancelled_at);
            $this->assertSame($b->id, $fresh->area_closure_id);
            $this->assertSame('Black Friday', $fresh->cancel_reason);
            $updated = ChangeLogEntry::query()->forSubject($shift)->ofAction('shift.updated')->sole();
            $this->assertSame('closure.updated', $updated->details['via']);
            $this->assertSame(['old' => $a->id, 'new' => $b->id], $updated->changes['area_closure_id']);
            $this->assertSame(0, ChangeLogEntry::query()->forSubject($shift)->ofAction('shift.restored')->count());
        }
        Notification::assertNothingSent();
    }

    public function test_extending_a_closure_cancels_the_new_days_and_emails_once(): void
    {
        $fri = $this->shiftOn('2026-11-27', $this->robin);
        $sat = $this->shiftOn('2026-11-28', $this->robin, $this->casey);
        $closure = $this->close('2026-11-25', '2026-11-26');
        $coordinator = $this->coordinator();
        Notification::fake();

        app(UpdateClosure::class)->handle($coordinator, $closure, '2026-11-25', '2026-11-28', 'Thanksgiving');

        foreach ([$fri, $sat] as $shift) {
            $fresh = $shift->fresh();
            $this->assertNotNull($fresh->cancelled_at);
            $this->assertSame($closure->id, $fresh->area_closure_id);
            $this->assertSame('Thanksgiving', $fresh->cancel_reason);
            $cancelled = ChangeLogEntry::query()->forSubject($shift)->ofAction('shift.cancelled')->sole();
            $this->assertSame('closure.updated', $cancelled->details['via']);
            $this->assertSame($closure->id, $cancelled->details['area_closure_id']);
        }

        Notification::assertSentToTimes($this->robin, ShiftCancelledNotification::class, 1);
        Notification::assertSentTo($this->robin, ShiftCancelledNotification::class, fn (ShiftCancelledNotification $notification) => $notification->reason === 'Thanksgiving' && count($notification->shifts) === 2);
        Notification::assertSentToTimes($this->casey, ShiftCancelledNotification::class, 1);
        $this->assertSame(['old' => '2026-11-26', 'new' => '2026-11-28'], ChangeLogEntry::query()->forSubject($closure)->ofAction('closure.updated')->sole()->changes['ends_on']);
    }

    public function test_moving_a_closure_sends_each_person_one_email_about_both(): void
    {
        $wed = $this->shiftOn('2026-11-25', $this->robin);
        $sat = $this->shiftOn('2026-11-28', $this->robin);
        $closure = $this->close('2026-11-25', '2026-11-26');
        Notification::fake();

        app(UpdateClosure::class)->handle($this->coordinator(), $closure, '2026-11-26', '2026-11-28', 'Thanksgiving');

        $this->assertNull($wed->fresh()->cancelled_at);
        $this->assertNotNull($sat->fresh()->cancelled_at);
        Notification::assertSentToTimes($this->robin, ShiftCancelledNotification::class, 1);
        Notification::assertNotSentTo($this->robin, ShiftRestoredNotification::class);
        Notification::assertSentTo($this->robin, ShiftCancelledNotification::class, function (ShiftCancelledNotification $notification) {
            $mail = $notification->toMail($this->robin);
            $this->assertSame('Changes to your shifts', $mail->subject);
            $this->assertContains('The Front desk shift on Wednesday, November 25, 3:30 to 6:30pm is back on, and you\'re still on it. If you can\'t make it, let your coordinator know.', $mail->introLines);

            return $notification->shifts === ['Front desk shift on Saturday, November 28, 3:30 to 6:30pm'];
        });
    }

    public function test_changing_only_the_reason_updates_the_shifts_without_email(): void
    {
        $wed = $this->shiftOn('2026-11-25', $this->robin);
        $closure = $this->close('2026-11-25', '2026-11-26');
        $coordinator = $this->coordinator();
        Notification::fake();

        app(UpdateClosure::class)->handle($coordinator, $closure, '2026-11-25', '2026-11-26', 'Thanksgiving break');

        $this->assertSame('Thanksgiving break', $wed->fresh()->cancel_reason);
        $this->assertNotNull($wed->fresh()->cancelled_at);
        $updated = ChangeLogEntry::query()->forSubject($wed)->ofAction('shift.updated')->sole();
        $this->assertSame('closure.updated', $updated->details['via']);
        $this->assertSame(['old' => 'Thanksgiving', 'new' => 'Thanksgiving break'], $updated->changes['cancel_reason']);
        $this->assertSame(['old' => 'Thanksgiving', 'new' => 'Thanksgiving break'], ChangeLogEntry::query()->forSubject($closure)->ofAction('closure.updated')->sole()->changes['reason']);
        Notification::assertNothingSent();
    }

    public function test_a_started_closure_keeps_its_first_day(): void
    {
        // Made on Tuesday, October 13, before its first shift started.
        $this->travelTo(CarbonImmutable::parse('2026-10-13 09:00', self::TIMEZONE));
        $tue = $this->shiftOn('2026-10-13', $this->robin);
        $wed = $this->shiftOn('2026-10-14', $this->robin);
        $closure = $this->close('2026-10-13', '2026-10-20', 'Floor repairs');
        $slot = $this->thursdaySlot();
        $this->assertSame(0, $slot->shifts()->where('local_date', '2026-10-15')->count());
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));
        $coordinator = $this->coordinator();
        Notification::fake();

        try {
            app(UpdateClosure::class)->handle($coordinator, $closure, '2026-10-14', '2026-10-20', 'Floor repairs');
            $this->fail('Changing the first day of a started closure should be refused.');
        } catch (DomainException $e) {
            $this->assertSame('This closure has already started, so only its last day can change. To fix days that have passed, remove it.', $e->getMessage());
        }

        // Ending it yesterday reopens today.
        app(UpdateClosure::class)->handle($coordinator, $closure->fresh(), '2026-10-13', '2026-10-13', 'Floor repairs');

        $this->assertSame('2026-10-13', $closure->fresh()->ends_on->toDateString());
        $this->assertNotNull($tue->fresh()->cancelled_at);
        $this->assertSame($closure->id, $tue->fresh()->area_closure_id);
        $this->assertNull($wed->fresh()->cancelled_at);
        $this->assertNull($slot->shifts()->where('local_date', '2026-10-15')->sole()->cancelled_at);
        Notification::assertSentToTimes($this->robin, ShiftRestoredNotification::class, 1);
    }

    public function test_a_closure_that_ended_cannot_be_stretched_over_days_that_passed(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04 09:00', self::TIMEZONE));
        $closure = $this->close('2026-10-05', '2026-10-07', 'Floor repairs');
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('This closure has ended. To close more days, add a new closure.');

        app(UpdateClosure::class)->handle($this->coordinator(), $closure, '2026-10-05', '2026-10-20', 'Floor repairs');
    }

    public function test_a_started_closure_cannot_end_before_yesterday(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00', self::TIMEZONE));
        $closure = $this->close('2026-10-12', '2026-10-20', 'Floor repairs');
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));

        try {
            app(UpdateClosure::class)->handle($this->coordinator(), $closure, '2026-10-12', '2026-10-12', 'Floor repairs');
            $this->fail('A last day before yesterday should be refused.');
        } catch (DomainException $e) {
            $this->assertSame('The last day can\'t be before yesterday.', $e->getMessage());
        }

        $this->assertSame('2026-10-20', $closure->fresh()->ends_on->toDateString());
    }

    public function test_updating_checks_the_area_and_the_values(): void
    {
        $closure = $this->close('2026-11-25', '2026-11-26');

        try {
            app(UpdateClosure::class)->handle($this->withRole(Role::AreaLead, $this->frontDesk), $closure, '2026-11-25', '2026-11-27', 'Thanksgiving');
            $this->fail('An area lead should not change a closure.');
        } catch (AuthorizationException) {
        }

        foreach ([
            ['2026-11-25', '2026-11-24', 'Thanksgiving', 'The last day closed has to be on or after the first day.'],
            ['2026-11-25', 'soon', 'Thanksgiving', 'Pick the first and last day closed.'],
            ['2026-11-25', '2026-11-26', ' ', 'Say why it\'s closed, so volunteers know.'],
            ['2026-10-01', '2026-10-02', 'Thanksgiving', 'Pick days from today on.'],
        ] as [$from, $to, $reason, $message]) {
            try {
                app(UpdateClosure::class)->handle($this->coordinator(), $closure, $from, $to, $reason);
                $this->fail('Expected: '.$message);
            } catch (DomainException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }

        $this->assertSame('2026-11-26', $closure->fresh()->ends_on->toDateString());
        $this->assertSame(0, ChangeLogEntry::query()->ofAction('closure.updated')->count());
    }

    public function test_a_day_whose_weekly_slot_no_longer_runs_stays_cancelled(): void
    {
        $slot = $this->thursdaySlot();
        $thanksgiving = $slot->shifts()->where('local_date', '2026-11-26')->sole();
        Assignment::create(['shift_id' => $thanksgiving->id, 'user_id' => $this->robin->id, 'type' => AssignmentType::Regular]);
        $closure = $this->close('2026-11-26', '2026-11-26');
        // The slot stops before Thanksgiving while the closure stands.
        WeeklySlot::query()->whereKey($slot->id)->update(['valid_until' => '2026-11-19']);
        $coordinator = $this->coordinator();
        Notification::fake();

        app(RemoveClosure::class)->handle($coordinator, $closure);

        $fresh = $thanksgiving->fresh();
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertNull($fresh->area_closure_id);
        $this->assertSame('The weekly slot changed', $fresh->cancel_reason);
        $updated = ChangeLogEntry::query()->forSubject($thanksgiving)->ofAction('shift.updated')->sole();
        $this->assertSame('closure.removed', $updated->details['via']);
        $this->assertSame(['old' => 'Thanksgiving', 'new' => 'The weekly slot changed'], $updated->changes['cancel_reason']);
        $this->assertSame(0, ChangeLogEntry::query()->forSubject($thanksgiving)->ofAction('shift.restored')->count());
        Notification::assertNothingSent();
    }

    public function test_removing_a_past_closure_only_fixes_the_label(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04 09:00', self::TIMEZONE));
        $shifts = [$this->shiftOn('2026-10-05', $this->robin), $this->shiftOn('2026-10-06'), $this->shiftOn('2026-10-07')];
        $closure = $this->close('2026-10-05', '2026-10-07', 'Floor repairs');
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));
        $coordinator = $this->coordinator();
        Notification::fake();

        app(RemoveClosure::class)->handle($coordinator, $closure);

        $this->assertNull(AreaClosure::query()->find($closure->id));
        $entry = ChangeLogEntry::query()->forSubject($closure)->ofAction('closure.removed')->sole();
        $this->assertSame($coordinator->id, $entry->actor_id);
        $this->assertSame(['old' => '2026-10-05', 'new' => null], $entry->changes['starts_on']);
        $this->assertSame(['old' => '2026-10-07', 'new' => null], $entry->changes['ends_on']);
        $this->assertSame(['old' => 'Floor repairs', 'new' => null], $entry->changes['reason']);
        $this->assertSame('Removed the Front desk closure for Oct 5 to Oct 7', Describe::entry($entry, $coordinator));

        foreach ($shifts as $shift) {
            $fresh = $shift->fresh();
            $this->assertNotNull($fresh->cancelled_at);
            $this->assertSame('Floor repairs', $fresh->cancel_reason);
            $this->assertNull($fresh->area_closure_id);
        }
        Notification::assertNothingSent();

        $this->actingAs($coordinator)->get(route('calendar.week', ['date' => '2026-10-05']))->assertOk()->assertDontSee('Closed: Floor repairs');
    }

    public function test_removing_a_closure_that_spans_today_brings_back_only_the_future(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00', self::TIMEZONE));
        $mon = $this->shiftOn('2026-10-12', $this->robin);
        $fri = $this->shiftOn('2026-10-16', $this->robin);
        $closure = $this->close('2026-10-12', '2026-10-16', 'Floor repairs');
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));
        $coordinator = $this->coordinator();
        Notification::fake();

        app(RemoveClosure::class)->handle($coordinator, $closure);

        $this->assertNotNull($mon->fresh()->cancelled_at);
        $this->assertSame('Floor repairs', $mon->fresh()->cancel_reason);
        $this->assertNull($fri->fresh()->cancelled_at);
        $this->assertSame('closure.removed', ChangeLogEntry::query()->forSubject($fri)->ofAction('shift.restored')->sole()->details['via']);
        $this->assertSame(0, ChangeLogEntry::query()->forSubject($mon)->ofAction('shift.restored')->count());
        Notification::assertSentToTimes($this->robin, ShiftRestoredNotification::class, 1);
    }

    public function test_only_coordinators_see_the_closure_bar_as_a_link(): void
    {
        $closure = $this->close('2026-11-26', '2026-11-27');
        $link = 'href="'.route('calendar.closures.edit', $closure).'"';
        $coordinator = $this->coordinator();
        $lead = $this->withRole(Role::AreaLead, $this->frontDesk);

        foreach ([route('calendar.week', ['date' => '2026-11-26']), route('calendar.day', ['date' => '2026-11-26']), route('calendar.month', ['month' => '2026-11'])] as $url) {
            $this->actingAs($coordinator)->get($url)->assertOk()->assertSee($link, false)->assertSee('Closed: Thanksgiving');
            $this->actingAs($lead)->get($url)->assertOk()->assertDontSee($link, false)->assertSee('Closed: Thanksgiving');
        }

        $this->actingAs($lead)->get(route('calendar.closures.edit', $closure))->assertForbidden();
        $this->actingAs($lead)->post(route('calendar.closures.update', $closure), ['from' => '2026-11-26', 'to' => '2026-11-28', 'reason' => 'Thanksgiving'])->assertForbidden();
        $this->actingAs($lead)->post(route('calendar.closures.remove', $closure))->assertForbidden();
    }

    public function test_a_reason_shared_by_several_areas_links_each_areas_closure(): void
    {
        $shop = Area::factory()->create(['name' => 'Shop']);
        $closures = app(CreateClosure::class)->handle($this->coordinator(), [$this->frontDesk->id, $shop->id], '2026-11-26', '2026-11-26', 'Thanksgiving');
        $deskLink = '<a href="'.route('calendar.closures.edit', $closures->firstWhere('area_id', $this->frontDesk->id)).'">Front desk</a>';
        $shopLink = '<a href="'.route('calendar.closures.edit', $closures->firstWhere('area_id', $shop->id)).'">Shop</a>';
        $coordinator = $this->coordinator();
        // Only coordinators and admins can change a closure, so a Shop lead sees the label without links.
        $lead = $this->withRole(Role::AreaLead, $shop);

        foreach ([route('calendar.week', ['date' => '2026-11-26']), route('calendar.day', ['date' => '2026-11-26']), route('calendar.month', ['month' => '2026-11'])] as $url) {
            $this->actingAs($coordinator)->get($url)->assertOk()
                ->assertSee('Closed: Thanksgiving. Change: '.$deskLink.', '.$shopLink, false);
            $this->actingAs($lead)->get($url)->assertOk()
                ->assertSee('<span class="closed">Closed: Thanksgiving</span>', false)
                ->assertDontSee('closures/', false);
        }
    }

    public function test_the_remove_step_names_a_single_day_once(): void
    {
        $closure = $this->close('2026-11-25', '2026-11-25');

        $this->actingAs($this->coordinator())->get(route('calendar.closures.edit', ['closure' => $closure, 'remove' => 1]))->assertOk()
            ->assertSee('Remove the Front desk closure for Nov 25? No shifts come back.');
        app(RemoveClosure::class)->handle($this->coordinator(), $closure);
        $entry = ChangeLogEntry::query()->forSubject($closure)->ofAction('closure.removed')->sole();
        $this->assertSame('Removed the Front desk closure for Nov 25', Describe::entry($entry, $this->coordinator()));
    }

    public function test_the_shift_panel_links_to_the_closure_for_coordinators(): void
    {
        $shift = $this->shiftOn('2026-11-26', $this->robin);
        $closure = $this->close('2026-11-26', '2026-11-26');
        $link = 'href="'.route('calendar.closures.edit', $closure).'"';

        $this->actingAs($this->coordinator())->get(route('calendar.shift', $shift))->assertOk()->assertSee($link, false);
        $this->actingAs($this->withRole(Role::AreaLead, $this->frontDesk))->get(route('calendar.shift', $shift))->assertOk()
            ->assertDontSee($link, false)
            ->assertSee('Cancelled by the Thanksgiving closure. Change the closure to bring it back.');
    }

    public function test_the_edit_page_shows_what_it_changes_and_saving_says_what_it_did(): void
    {
        $this->shiftOn('2026-11-27', $this->robin);
        $this->shiftOn('2026-11-28', $this->casey);
        $closure = $this->close('2026-11-25', '2026-11-27');
        $coordinator = $this->coordinator();

        $this->actingAs($coordinator)->get(route('calendar.closures.edit', ['closure' => $closure, 'from' => '2026-11-25', 'to' => '2026-11-28']))->assertOk()
            ->assertSee('Change the closure')
            ->assertSee('What this changes')
            ->assertSee('Save the closure')
            ->assertSee('Front desk')
            ->assertDontSee('name="area[]"', false)
            ->assertSeeInOrder(['Cancels', 'Sat Nov 28', 'Casey Example'])
            ->assertSee('Remove this closure');

        $this->actingAs($coordinator)->get(route('calendar.closures.edit', ['closure' => $closure, 'from' => '2026-11-25', 'to' => '2026-11-26']))->assertOk()
            ->assertSeeInOrder(['Brings back', 'Fri Nov 27', 'Robin Example']);

        Notification::fake();
        $this->actingAs($coordinator)->post(route('calendar.closures.update', $closure), ['from' => '2026-11-25', 'to' => '2026-11-26', 'reason' => 'Thanksgiving'])
            ->assertRedirect(route('calendar.week', ['date' => '2026-11-25']))
            ->assertSessionHas('status', 'Changed the Front desk closure to Wednesday, November 25 to Thursday, November 26. Brought back 1 shift and emailed 1 person.');
    }

    public function test_the_remove_step_asks_first_and_removing_says_what_it_did(): void
    {
        $this->shiftOn('2026-11-27', $this->robin);
        $closure = $this->close('2026-11-25', '2026-11-27');
        $coordinator = $this->coordinator();

        $this->actingAs($coordinator)->get(route('calendar.closures.edit', ['closure' => $closure, 'remove' => 1]))->assertOk()
            ->assertSee('Remove the Front desk closure for Nov 25 to Nov 27? 1 shift comes back and 1 person is emailed.')
            ->assertDontSee('Days that have passed lose the Closed label.');

        Notification::fake();
        $this->actingAs($coordinator)->post(route('calendar.closures.remove', $closure))
            ->assertRedirect(route('calendar.week', ['date' => '2026-11-25']))
            ->assertSessionHas('status', 'Removed the Front desk closure for Wednesday, November 25 to Friday, November 27. Brought back 1 shift and emailed 1 person.');
        $this->assertNull(AreaClosure::query()->find($closure->id));
    }

    public function test_the_remove_step_for_a_started_closure_says_past_days_stay_cancelled(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00', self::TIMEZONE));
        $closure = $this->close('2026-10-12', '2026-10-16', 'Floor repairs');
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));

        $this->actingAs($this->coordinator())->get(route('calendar.closures.edit', ['closure' => $closure, 'remove' => 1]))->assertOk()
            ->assertSee('Remove the Front desk closure for Oct 12 to Oct 16? No shifts come back.')
            ->assertSee('Days that have passed lose the Closed label. Their shifts stay cancelled.');
    }
}
