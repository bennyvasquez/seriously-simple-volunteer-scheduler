<?php

namespace Tests\Feature\Scheduling;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\CancelShift;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\RemoveFromShift;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\RemovedFromShiftsNotification;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class RemoveFromShiftTest extends TestCase
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

    /** A shift with Robin on it as a regular. */
    private function robinOn(string $date, ?Area $area = null): Shift
    {
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator(), $area ?? $this->frontDesk, $date, '18:30', '21:30', 2, 0, null);
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $this->robin->id, 'type' => AssignmentType::Regular]);

        return $shift;
    }

    private function assignmentOn(Shift $shift): Assignment
    {
        return Assignment::query()->where('shift_id', $shift->id)->where('user_id', $this->robin->id)->firstOrFail();
    }

    public function test_removing_someone_from_a_shift_deletes_the_assignment_logs_it_and_emails_them(): void
    {
        Notification::fake();
        $shift = $this->robinOn('2026-10-22');
        $assignment = $this->assignmentOn($shift);
        $coordinator = $this->coordinator();

        app(RemoveFromShift::class)->one($coordinator, $shift, $assignment);

        $this->assertDatabaseMissing('assignments', ['id' => $assignment->id]);
        $entry = ChangeLogEntry::query()->forSubject($shift)->ofAction('assignment.removed')->sole();
        $this->assertSame($this->robin->id, $entry->affected_user_id);
        $this->assertSame($coordinator->id, $entry->actor_id);
        $this->assertSame(['assignment_id' => $assignment->id, 'type' => 'regular'], $entry->details);
        $this->assertSame(['user_id' => ['old' => $this->robin->id, 'new' => null]], $entry->changes);
        Notification::assertSentToTimes($this->robin, RemovedFromShiftsNotification::class, 1);
    }

    public function test_removing_someone_from_a_range_sends_one_email_listing_every_shift(): void
    {
        Notification::fake();
        $inRange = collect(['2026-10-20', '2026-10-27', '2026-10-31'])->map(fn (string $date) => $this->robinOn($date));
        $later = $this->robinOn('2026-11-02');

        $removed = app(RemoveFromShift::class)->range($this->coordinator(), $this->robin, '2026-10-20', '2026-10-31');

        $this->assertCount(3, $removed);
        $this->assertSame(1, Assignment::query()->where('user_id', $this->robin->id)->count());
        $this->assertTrue($later->assignments()->where('user_id', $this->robin->id)->exists());
        $entries = ChangeLogEntry::query()->ofAction('assignment.removed')->get();
        $this->assertCount(3, $entries);
        $this->assertSame(['range', '2026-10-20', '2026-10-31'], [$entries[0]->details['via'], $entries[0]->details['from'], $entries[0]->details['to']]);
        Notification::assertSentToTimes($this->robin, RemovedFromShiftsNotification::class, 1);
        Notification::assertSentTo($this->robin, RemovedFromShiftsNotification::class, fn ($notification) => count($notification->shifts) === 3
            && $notification->toMail($this->robin)->subject === 'You\'ve been removed from 3 shifts');
        $this->assertCount(3, $inRange);
    }

    public function test_the_email_reads_the_same_for_one_shift_and_several(): void
    {
        $shift = $this->robinOn('2026-10-22')->load('area');
        $mail = (new RemovedFromShiftsNotification(collect([$shift])))->toMail($this->robin);

        $this->assertSame('You\'ve been removed from a Front desk shift', $mail->subject);
        $this->assertSame(['You\'re no longer on the Front desk shift on Thursday, October 22, 6:30 to 9:30pm.', 'If you didn\'t expect this, ask your coordinator.'], $mail->introLines);
    }

    public function test_an_area_lead_range_leaves_out_other_areas(): void
    {
        Notification::fake();
        $desk = $this->robinOn('2026-10-22');
        $events = $this->robinOn('2026-10-23', $this->hostedEvents);
        $lead = $this->coordinator(Role::AreaLead, $this->frontDesk);

        $preview = app(RemoveFromShift::class)->rangePreview($lead, $this->robin, '2026-10-20', '2026-10-31');
        $this->assertSame([$desk->id], $preview['shifts']->pluck('id')->all());
        $this->assertSame(1, $preview['otherAreas']);

        app(RemoveFromShift::class)->range($lead, $this->robin, '2026-10-20', '2026-10-31');

        $this->assertFalse($desk->assignments()->exists());
        $this->assertTrue($events->assignments()->exists());
    }

    public function test_an_area_lead_cannot_remove_someone_from_another_areas_shift(): void
    {
        $events = $this->robinOn('2026-10-23', $this->hostedEvents);

        $this->expectException(AuthorizationException::class);
        app(RemoveFromShift::class)->one($this->coordinator(Role::AreaLead, $this->frontDesk), $events, $this->assignmentOn($events));
    }

    public function test_a_range_with_nothing_in_it_is_refused(): void
    {
        $this->robinOn('2026-11-02');

        try {
            app(RemoveFromShift::class)->range($this->coordinator(), $this->robin, '2026-10-20', '2026-10-31');
            $this->fail('A range with no shifts was accepted.');
        } catch (DomainException $e) {
            $this->assertSame('Robin isn\'t on any shifts you manage on those dates.', $e->getMessage());
        }
    }

    public function test_a_range_cannot_start_before_today(): void
    {
        $this->expectExceptionMessage('Pick dates from today on.');
        app(RemoveFromShift::class)->range($this->coordinator(), $this->robin, '2026-10-13', '2026-10-31');
    }

    public function test_removing_someone_from_a_started_shift_is_refused(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 07:00', self::TIMEZONE));
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator(), $this->frontDesk, '2026-10-14', '08:00', '12:00', 1, 0, null);
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $this->robin->id, 'type' => AssignmentType::Regular]);
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));

        try {
            app(RemoveFromShift::class)->one($this->coordinator(), $shift, $this->assignmentOn($shift));
            $this->fail('Someone was removed from a shift that had started.');
        } catch (DomainException $e) {
            $this->assertSame('This shift has already started.', $e->getMessage());
        }

        $this->assertSame(1, Assignment::query()->count());

        // A range that reaches today leaves the started shift alone too.
        $this->expectExceptionMessage('Robin isn\'t on any shifts you manage on those dates.');
        app(RemoveFromShift::class)->range($this->coordinator(), $this->robin, '2026-10-14', '2026-10-14');
    }

    public function test_removing_someone_who_is_already_off_the_shift_is_refused(): void
    {
        $shift = $this->robinOn('2026-10-22');
        $assignment = $this->assignmentOn($shift);
        app(RemoveFromShift::class)->one($this->coordinator(), $shift, $assignment);

        $this->expectExceptionMessage('Robin isn\'t on this shift any more.');
        app(RemoveFromShift::class)->one($this->coordinator(), $shift, $assignment);
    }

    public function test_removing_someone_from_a_cancelled_shift_sends_no_email(): void
    {
        Notification::fake();
        $shift = $this->robinOn('2026-10-22');
        app(CancelShift::class)->handle($this->coordinator(), $shift, 'Power outage');
        Notification::fake();

        app(RemoveFromShift::class)->one($this->coordinator(), $shift, $this->assignmentOn($shift));

        $this->assertSame(0, Assignment::query()->count());
        Notification::assertNothingSent();
    }

    public function test_a_range_with_a_cancelled_shift_emails_only_the_shifts_still_on(): void
    {
        Notification::fake();
        $cancelled = $this->robinOn('2026-10-22');
        $this->robinOn('2026-10-23');
        app(CancelShift::class)->handle($this->coordinator(), $cancelled, 'Power outage');
        Notification::fake();

        $removed = app(RemoveFromShift::class)->range($this->coordinator(), $this->robin, '2026-10-20', '2026-10-31');

        $this->assertCount(2, $removed);
        Notification::assertSentTo($this->robin, RemovedFromShiftsNotification::class, fn ($notification) => count($notification->shifts) === 1);
    }

    public function test_the_remove_page_asks_which_shifts_without_a_default(): void
    {
        $shift = $this->robinOn('2026-10-22');
        $assignment = $this->assignmentOn($shift);
        $coordinator = $this->coordinator();

        $page = $this->actingAs($coordinator)->get(route('calendar.shift.remove-person', [$shift, 'assignment' => $assignment->id]));
        $page->assertOk()->assertSee('Remove Robin Example from shifts')->assertSee('Just Thursday, October 22');
        $this->assertStringNotContainsString('checked', $this->radios($page->getContent()));

        $this->actingAs($coordinator)->post(route('calendar.shift.remove-person.store', $shift), ['assignment' => $assignment->id])
            ->assertRedirect()->assertSessionHasErrors(['which' => 'Pick just this date or a range of dates.']);
        $this->assertDatabaseHas('assignments', ['id' => $assignment->id]);
    }

    /** The radio buttons' markup, to check none is ticked. */
    private function radios(string $html): string
    {
        preg_match_all('/<input type="radio"[^>]*>/', $html, $matches);

        return implode("\n", $matches[0]);
    }

    public function test_removing_from_just_this_date_through_the_page(): void
    {
        $shift = $this->robinOn('2026-10-22');
        $assignment = $this->assignmentOn($shift);

        $this->actingAs($this->coordinator())->post(route('calendar.shift.remove-person.store', $shift), ['assignment' => $assignment->id, 'which' => 'this'])
            ->assertRedirect(route('calendar.shift', $shift))
            ->assertSessionHas('status', 'Robin was removed from 1 shift. They were emailed.');
        $this->assertDatabaseMissing('assignments', ['id' => $assignment->id]);
    }

    public function test_a_range_is_checked_first_and_removed_only_with_the_dates_that_were_checked(): void
    {
        $shift = $this->robinOn('2026-10-22');
        $this->robinOn('2026-10-23');
        $this->robinOn('2026-10-23', $this->hostedEvents);
        $assignment = $this->assignmentOn($shift);
        $lead = $this->coordinator(Role::AreaLead, $this->frontDesk);
        $body = ['assignment' => $assignment->id, 'which' => 'range', 'from' => '2026-10-22', 'to' => '2026-10-31'];

        // "Check which shifts" and a bare remove both go to the list without changing anything.
        foreach ([['check' => 1], []] as $extra) {
            $this->actingAs($lead)->post(route('calendar.shift.remove-person.store', $shift), $body + $extra)
                ->assertRedirect(route('calendar.shift.remove-person', [$shift, 'assignment' => $assignment->id, 'which' => 'range', 'from' => '2026-10-22', 'to' => '2026-10-31', 'check' => 1]));
        }
        $this->assertSame(3, Assignment::query()->count());

        $this->actingAs($lead)->get(route('calendar.shift.remove-person', [$shift, 'assignment' => $assignment->id, 'which' => 'range', 'from' => '2026-10-22', 'to' => '2026-10-31', 'check' => 1]))
            ->assertOk()->assertSee('Remove Robin from 2 shifts')->assertSee('1 shift in areas you don\'t manage isn\'t included.', false);

        // Different dates than the ones that were checked go back to the list.
        $this->actingAs($lead)->post(route('calendar.shift.remove-person.store', $shift), $body + ['confirmed' => 1, 'checked_from' => '2026-10-22', 'checked_to' => '2026-10-23'])
            ->assertRedirect();
        $this->assertSame(3, Assignment::query()->count());

        $this->actingAs($lead)->post(route('calendar.shift.remove-person.store', $shift), $body + ['confirmed' => 1, 'checked_from' => '2026-10-22', 'checked_to' => '2026-10-31'])
            ->assertRedirect(route('calendar.shift', $shift))
            ->assertSessionHas('status', 'Robin was removed from 2 shifts. They were emailed.');
        $this->assertSame(1, Assignment::query()->count());
    }

    public function test_the_shift_panel_links_to_removing_people_only_while_the_shift_is_ahead(): void
    {
        $shift = $this->robinOn('2026-10-22');
        $coordinator = $this->coordinator();

        $this->actingAs($coordinator)->get(route('calendar.shift', $shift))->assertOk()->assertSee('Remove from shift');
        $this->actingAs($this->coordinator(Role::AreaLead, $this->hostedEvents))->get(route('calendar.shift', $shift))->assertOk()->assertDontSee('Remove from shift');
    }
}
