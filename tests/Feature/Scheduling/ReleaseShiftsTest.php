<?php

namespace Tests\Feature\Scheduling;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\AddRegular;
use App\Actions\Scheduling\CancelShift;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\GenerateShifts;
use App\Actions\Scheduling\ReleaseShifts;
use App\ChangeLog\Describe;
use App\Cover\CoverLadder;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Enums\ShadowSpotNotice;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\CoverRequest;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Models\WeeklySlot;
use App\Notifications\CoverRequestNotification;
use App\Notifications\CoverUrgentNotification;
use App\Notifications\ShadowLeftNotification;
use App\Notifications\ShadowSpotNotification;
use App\Notifications\ShiftsReleasedNotification;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class ReleaseShiftsTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private Area $frontDesk;

    private Area $hostedEvents;

    private User $coordinator;

    private User $robin;

    private User $morgan;

    private User $sam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutDefer();

        // Wednesday, October 14, 2026, 9am in Detroit.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));
        Organization::factory()->create(['name' => 'Example Makerspace', 'timezone' => self::TIMEZONE, 'weeks_ahead' => 8]);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk', 'lead_name' => 'Shift lead']);
        $this->hostedEvents = Area::factory()->create(['name' => 'Hosted events']);
        $this->coordinator = $this->withRole('Chris Coordinator', Role::Coordinator);
        $this->robin = User::factory()->create(['name' => 'Robin Example']);
        $this->morgan = User::factory()->create(['name' => 'Morgan Example']);
        $this->sam = User::factory()->create(['name' => 'Sam Example']);
    }

    private function withRole(string $name, Role $role, ?Area $area = null): User
    {
        $user = User::factory()->create(['name' => $name]);
        app(GrantRole::class)->handle(null, $user, $role, $area);
        $this->enableTwoFactor($user);

        return $user;
    }

    /** A 6:30 to 9:30pm shift with Robin on it as a regular. */
    private function robinOn(string $date, ?Area $area = null, int $peopleNeeded = 1): Shift
    {
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator, $area ?? $this->frontDesk, $date, '18:30', '21:30', $peopleNeeded, 1, null);
        $this->on($shift, $this->robin);

        return $shift;
    }

    private function on(Shift $shift, User $person, AssignmentType $type = AssignmentType::Regular): void
    {
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $person->id, 'type' => $type]);
    }

    private function text(MailMessage $mail): string
    {
        return implode("\n", array_merge([$mail->greeting], $mail->introLines, $mail->outroLines));
    }

    public function test_releasing_one_shift_frees_the_person_and_asks_everyone_who_could_take_it(): void
    {
        Notification::fake();
        $shift = $this->robinOn('2026-10-22');
        $assignment = Assignment::query()->where('shift_id', $shift->id)->sole();

        $request = app(ReleaseShifts::class)->one($this->robin, $shift, "  The key is in the drawer.\n ");

        $this->assertDatabaseMissing('assignments', ['id' => $assignment->id]);
        $request = CoverRequest::query()->whereKey($request->id)->sole();
        $this->assertSame($shift->id, $request->shift_id);
        $this->assertSame($this->robin->id, $request->released_by);
        $this->assertSame('The key is in the drawer.', $request->note);
        $this->assertSame(3, $request->notified_count);

        foreach ([$this->coordinator, $this->morgan, $this->sam] as $person) {
            Notification::assertSentToTimes($person, CoverRequestNotification::class, 1);
            Notification::assertSentTo($person, CoverRequestNotification::class, function (CoverRequestNotification $notification) use ($person) {
                $mail = $notification->toMail($person);

                return $mail->subject === 'Can you cover Front desk on Thursday, October 22?'
                    && str_contains($this->text($mail), 'Robin can\'t make their Front desk shift. Can you take it?')
                    && str_contains($this->text($mail), 'Note from Robin: The key is in the drawer.');
            });
        }
        Notification::assertNotSentTo($this->robin, CoverRequestNotification::class);
        Notification::assertSentToTimes($this->robin, ShiftsReleasedNotification::class, 1);
        Notification::assertSentTo($this->robin, ShiftsReleasedNotification::class, function (ShiftsReleasedNotification $notification) {
            $mail = $notification->toMail($this->robin);
            $text = $this->text($mail);

            return $mail->subject === 'You\'re off Thursday, October 22'
                && str_contains($text, 'Hi Robin,')
                && str_contains($text, 'You\'re off your Front desk shift on Thursday, October 22, 6:30 to 9:30pm.')
                && str_contains($text, 'We\'ve asked other volunteers to cover it, and we\'ll email you when someone does.')
                && str_contains($text, 'If you can make it after all, you can take it back from your home page while it\'s still open.')
                && ! str_contains($text, 'shadow')
                && ! str_contains($text, 'regular')
                && $mail->actionText === 'Open my shifts'
                && $mail->actionUrl === url('/');
        });

        $removed = ChangeLogEntry::query()->forSubject($shift)->ofAction('assignment.removed')->sole();
        $this->assertSame($this->robin->id, $removed->actor_id);
        $this->assertSame($this->robin->id, $removed->affected_user_id);
        $this->assertSame(['assignment_id' => $assignment->id, 'type' => 'regular', 'via' => 'release:one'], $removed->details);
        $this->assertSame(['user_id' => ['old' => $this->robin->id, 'new' => null]], $removed->changes);

        $created = ChangeLogEntry::query()->forSubject($shift)->ofAction('cover_request.created')->sole();
        $this->assertSame($this->robin->id, $created->actor_id);
        $this->assertSame($this->robin->id, $created->affected_user_id);
        $this->assertSame(['cover_request_id' => $request->id], $created->details);
        $this->assertEquals([
            'shift_id' => ['old' => null, 'new' => $shift->id],
            'released_by' => ['old' => null, 'new' => $this->robin->id],
            'note' => ['old' => null, 'new' => 'The key is in the drawer.'],
            'notified_count' => ['old' => null, 'new' => 3],
        ], $created->changes);

        $this->assertSame('Released their place on the Front desk shift on Thursday, October 22, 6:30 to 9:30pm', Describe::entry($removed, $this->coordinator));
        $this->assertSame('Cover request sent for Front desk, Thursday, October 22', Describe::entry($created, $this->coordinator));
    }

    public function test_an_empty_note_is_stored_as_no_note(): void
    {
        Notification::fake();

        $request = app(ReleaseShifts::class)->one($this->robin, $this->robinOn('2026-10-22'), "  \n ");

        $this->assertNull($request->fresh()->note);
    }

    public function test_a_range_releases_every_shift_in_it_and_sends_one_email_each(): void
    {
        Notification::fake();
        $inRange = collect(['2026-10-20', '2026-10-27', '2026-10-31'])->map(fn (string $date) => $this->robinOn($date));
        $later = $this->robinOn('2026-11-02');
        // Sam is on Hosted events at the same time as the October 27 shift, so Sam could take the other two.
        $busy = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->hostedEvents, '2026-10-27', '18:00', '20:00', 1, 0, null);
        $this->on($busy, $this->sam);

        $released = app(ReleaseShifts::class)->range($this->robin, '2026-10-20', '2026-10-31', 'Away for work.');

        $this->assertSame($inRange->pluck('id')->all(), $released->pluck('id')->all());
        $this->assertSame([$later->id], Assignment::query()->where('user_id', $this->robin->id)->pluck('shift_id')->all());
        $this->assertSame(3, CoverRequest::query()->where('released_by', $this->robin->id)->count());
        $this->assertSame(3, ChangeLogEntry::query()->ofAction('cover_request.created')->count());
        $removed = ChangeLogEntry::query()->ofAction('assignment.removed')->get();
        $this->assertCount(3, $removed);
        $this->assertTrue($removed->every(fn (ChangeLogEntry $entry) => $entry->details['via'] === 'release:range' && $entry->affected_user_id === $this->robin->id));

        Notification::assertSentToTimes($this->robin, ShiftsReleasedNotification::class, 1);
        Notification::assertSentTo($this->robin, ShiftsReleasedNotification::class, function (ShiftsReleasedNotification $notification) {
            $mail = $notification->toMail($this->robin);
            $text = $this->text($mail);

            return $mail->subject === 'You\'re off 3 shifts'
                && str_contains($text, 'Front desk, Tuesday, October 20, 6:30 to 9:30pm')
                && str_contains($text, 'Front desk, Tuesday, October 27, 6:30 to 9:30pm')
                && str_contains($text, 'Front desk, Saturday, October 31, 6:30 to 9:30pm')
                && ! str_contains($text, 'November 2');
        });

        Notification::assertSentToTimes($this->morgan, CoverRequestNotification::class, 1);
        Notification::assertSentTo($this->morgan, CoverRequestNotification::class, fn (CoverRequestNotification $notification) => count($notification->shifts) === 3
            && $notification->toMail($this->morgan)->subject === 'Can you cover Front desk on 3 dates?'
            && $notification->note === 'Away for work.');
        Notification::assertSentToTimes($this->sam, CoverRequestNotification::class, 1);
        Notification::assertSentTo($this->sam, CoverRequestNotification::class, fn (CoverRequestNotification $notification) => array_column($notification->shifts, 'date') === ['Tuesday, October 20', 'Saturday, October 31']);
        $this->assertSame(2, CoverRequest::query()->where('shift_id', $inRange[1]->id)->value('notified_count'));
    }

    public function test_the_range_preview_lists_the_shifts_and_changes_nothing(): void
    {
        $desk = $this->robinOn('2026-10-22');
        $events = $this->robinOn('2026-10-23', $this->hostedEvents);
        $this->robinOn('2026-11-02');

        $preview = app(ReleaseShifts::class)->rangePreview($this->robin, '2026-10-20', '2026-10-31');

        $this->assertSame([$desk->id, $events->id], $preview['shifts']->pluck('id')->all());
        $this->assertNull($preview['lastOnCalendar']);
        $this->assertSame('2026-12-08', app(ReleaseShifts::class)->rangePreview($this->robin, '2026-10-20', '2027-01-31')['lastOnCalendar']);
        $this->assertSame(3, Assignment::query()->where('user_id', $this->robin->id)->count());
        $this->assertSame(0, CoverRequest::query()->count());
    }

    public function test_a_range_refuses_bad_dates_and_dates_with_no_shifts(): void
    {
        $this->robinOn('2026-10-22');

        foreach ([
            ['2026-10-13', '2026-10-20', 'Pick dates from today on.'],
            ['2026-10-20', '2026-10-19', 'The last date can\'t be before the first date.'],
            ['soon', '2026-10-19', 'Pick two dates.'],
            ['2026-10-23', '2026-10-31', 'You\'re not on any shifts on those dates.'],
        ] as [$from, $to, $message]) {
            try {
                app(ReleaseShifts::class)->range($this->robin, $from, $to, null);
                $this->fail('Expected a refusal for '.$from.' to '.$to);
            } catch (DomainException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }

        $this->assertSame(1, Assignment::query()->where('user_id', $this->robin->id)->count());
    }

    public function test_the_regular_stays_the_regular_after_releasing_dates(): void
    {
        Notification::fake();
        $slot = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'weekday' => 4, 'start_time' => '18:30', 'end_time' => '21:30', 'people_needed' => 1]);
        app(GenerateShifts::class)->handle(Organization::current());
        $regular = app(AddRegular::class)->handle(null, $slot, $this->robin, '2026-10-15');
        $before = SlotRegular::query()->whereKey($regular->id)->sole()->getAttributes();
        $shift = $slot->shifts()->where('local_date', '2026-10-22')->sole();

        app(ReleaseShifts::class)->one($this->robin, $shift, null);

        $this->assertSame($before, SlotRegular::query()->whereKey($regular->id)->sole()->getAttributes());
        $this->assertFalse($shift->assignments()->exists());
        $this->assertTrue($slot->shifts()->where('local_date', '2026-10-29')->sole()->assignments()->where('user_id', $this->robin->id)->exists());
        Notification::assertSentTo($this->robin, ShiftsReleasedNotification::class, fn (ShiftsReleasedNotification $notification) => str_contains($this->text($notification->toMail($this->robin)), 'You stay the Thursday regular. This only changes Thursday, October 22.'));
    }

    public function test_shadows_are_told_only_when_nobody_but_shadows_is_left(): void
    {
        $casey = User::factory()->create(['name' => 'Casey Example']);
        $shift = $this->robinOn('2026-10-22', peopleNeeded: 2);
        $this->on($shift, $this->morgan);
        $this->on($shift, $casey, AssignmentType::Shadow);
        Notification::fake();

        app(ReleaseShifts::class)->one($this->robin, $shift, null);

        Notification::assertNothingSentTo($casey);
        Notification::assertSentTo($this->robin, ShiftsReleasedNotification::class, fn (ShiftsReleasedNotification $notification) => ! str_contains($this->text($notification->toMail($this->robin)), 'shadow'));

        app(ReleaseShifts::class)->one($this->morgan, $shift, null);

        Notification::assertSentToTimes($casey, ShadowSpotNotification::class, 1);
        Notification::assertSentTo($casey, ShadowSpotNotification::class, fn (ShadowSpotNotification $notification) => $notification->notice === ShadowSpotNotice::OnHold
            && $notification->toMail($casey)->subject === 'Your shadow shift on Thursday, October 22 is on hold'
            && str_contains($this->text($notification->toMail($casey)), 'Morgan can\'t make their Front desk shift on Thursday, October 22'));
        Notification::assertNotSentTo($casey, CoverRequestNotification::class);
        Notification::assertSentTo($this->morgan, ShiftsReleasedNotification::class, fn (ShiftsReleasedNotification $notification) => str_contains($this->text($notification->toMail($this->morgan)), 'Casey was going to shadow you, so we\'ve told them too.'));
    }

    public function test_a_shadow_on_several_released_dates_gets_one_email(): void
    {
        $casey = User::factory()->create(['name' => 'Casey Example']);
        $first = $this->robinOn('2026-10-22');
        $second = $this->robinOn('2026-10-29');
        $this->on($first, $casey, AssignmentType::Shadow);
        $this->on($second, $casey, AssignmentType::Shadow);
        Notification::fake();

        app(ReleaseShifts::class)->range($this->robin, '2026-10-20', '2026-10-31', null);

        Notification::assertSentToTimes($casey, ShadowSpotNotification::class, 1);
        Notification::assertSentTo($casey, ShadowSpotNotification::class, function (ShadowSpotNotification $notification) use ($casey) {
            $mail = $notification->toMail($casey);
            $text = $this->text($mail);

            return $mail->subject === 'Your shadow shifts on 2 dates are on hold'
                && str_contains($text, 'Robin can\'t make these shifts, so right now there\'s nobody for you to shadow on them:')
                && str_contains($text, 'Front desk, Thursday, October 22, 6:30 to 9:30pm')
                && str_contains($text, 'Front desk, Thursday, October 29, 6:30 to 9:30pm');
        });
        Notification::assertSentTo($this->robin, ShiftsReleasedNotification::class, fn (ShiftsReleasedNotification $notification) => str_contains($this->text($notification->toMail($this->robin)), 'Casey was going to shadow you on these dates, so we\'ve told them too.'));
    }

    public function test_releasing_inside_24_hours_escalates_at_once_and_only_once(): void
    {
        $lead = $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $casey = User::factory()->create(['name' => 'Casey Example']);
        $shift = $this->robinOn('2026-10-15');
        $this->on($shift, $casey, AssignmentType::Shadow);
        Notification::fake();
        // Wednesday, 7pm: the shift starts in 23 and a half hours.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 19:00', self::TIMEZONE));

        $request = app(ReleaseShifts::class)->one($this->robin, $shift, null);

        $this->assertTrue($request->fresh()->escalated_at->equalTo(now()));
        $escalated = ChangeLogEntry::query()->forSubject($shift)->ofAction('cover_request.escalated')->sole();
        $this->assertSame($this->robin->id, $escalated->actor_id);
        Notification::assertSentToTimes($this->morgan, CoverRequestNotification::class, 1);
        Notification::assertSentToTimes($lead, CoverUrgentNotification::class, 1);
        Notification::assertSentToTimes($this->coordinator, CoverUrgentNotification::class, 1);
        Notification::assertSentToTimes($casey, ShadowSpotNotification::class, 1);
        Notification::assertSentTo($casey, ShadowSpotNotification::class, fn (ShadowSpotNotification $notification) => $notification->notice === ShadowSpotNotice::StillUncovered);

        $this->travelTo(CarbonImmutable::parse('2026-10-14 19:10', self::TIMEZONE));
        $this->assertSame(['resent' => 0, 'escalated' => 0], app(CoverLadder::class)->run());
        Notification::assertSentToTimes($lead, CoverUrgentNotification::class, 1);
        Notification::assertSentToTimes($casey, ShadowSpotNotification::class, 1);
    }

    public function test_a_shadow_who_cant_make_it_tells_the_coordinators(): void
    {
        $otherCoordinator = $this->withRole('Pat Coordinator', Role::Coordinator);
        $turnedOff = $this->withRole('Lee Coordinator', Role::Coordinator);
        $turnedOff->forceFill(['deactivated_at' => now()])->save();
        $lead = $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, '2026-10-22', '18:30', '21:30', 1, 1, null);
        $this->on($shift, $this->morgan);
        $this->on($shift, $this->robin, AssignmentType::Shadow);
        $assignment = Assignment::query()->where('shift_id', $shift->id)->where('user_id', $this->robin->id)->sole();
        Notification::fake();

        $this->assertNull(app(ReleaseShifts::class)->one($this->robin, $shift, 'Sorry!'));

        $this->assertDatabaseMissing('assignments', ['id' => $assignment->id]);
        $this->assertSame(0, CoverRequest::query()->count());
        foreach ([$this->coordinator, $otherCoordinator] as $person) {
            Notification::assertSentToTimes($person, ShadowLeftNotification::class, 1);
            Notification::assertSentTo($person, ShadowLeftNotification::class, function (ShadowLeftNotification $notification) use ($person, $shift) {
                $mail = $notification->toMail($person);
                $text = $this->text($mail);

                return $mail->subject === 'Robin can\'t make their shadow shift'
                    && str_contains($text, 'Front desk')
                    && str_contains($text, 'Thursday, October 22, 6:30 to 9:30pm')
                    && $mail->actionText === 'Open the shift'
                    && $mail->actionUrl === route('calendar.shift', $shift);
            });
        }
        foreach ([$turnedOff, $lead, $this->morgan, $this->sam, $this->robin] as $person) {
            Notification::assertNothingSentTo($person);
        }

        $entry = ChangeLogEntry::query()->forSubject($shift)->ofAction('assignment.removed')->sole();
        $this->assertSame($this->robin->id, $entry->actor_id);
        $this->assertSame($this->robin->id, $entry->affected_user_id);
        $this->assertSame(['assignment_id' => $assignment->id, 'type' => 'shadow', 'via' => 'release:one'], $entry->details);
        $this->assertSame(0, ChangeLogEntry::query()->ofAction('cover_request.created')->count());
        $this->assertSame('Gave up their shadow spot on the Front desk shift on Thursday, October 22, 6:30 to 9:30pm', Describe::entry($entry, $this->coordinator));
    }

    public function test_releasing_refuses_someone_not_on_the_shift(): void
    {
        $shift = $this->robinOn('2026-10-22');

        $this->expectExceptionObject(new DomainException('You\'re not on this shift any more.'));

        app(ReleaseShifts::class)->one($this->morgan, $shift, null);
    }

    public function test_releasing_refuses_a_cancelled_shift(): void
    {
        $shift = $this->robinOn('2026-10-22');
        app(CancelShift::class)->handle($this->coordinator, $shift, 'Power outage');
        Notification::fake();

        try {
            app(ReleaseShifts::class)->one($this->robin, $shift, null);
            $this->fail('Expected a refusal.');
        } catch (DomainException $exception) {
            $this->assertSame('This shift was cancelled.', $exception->getMessage());
        }

        $this->assertTrue($shift->assignments()->where('user_id', $this->robin->id)->exists());
        Notification::assertNothingSent();
    }

    public function test_releasing_refuses_a_shift_that_has_started(): void
    {
        $shift = $this->robinOn('2026-10-22');
        $this->travelTo(CarbonImmutable::parse('2026-10-22 18:30', self::TIMEZONE));

        $this->expectExceptionObject(new DomainException('This shift has already started.'));

        app(ReleaseShifts::class)->one($this->robin, $shift, null);
    }

    public function test_releasing_refuses_a_note_over_500_characters(): void
    {
        $shift = $this->robinOn('2026-10-22');

        try {
            app(ReleaseShifts::class)->one($this->robin, $shift, str_repeat('a', 501));
            $this->fail('Expected a refusal.');
        } catch (DomainException $exception) {
            $this->assertSame('Keep the note under 500 characters.', $exception->getMessage());
        }

        $this->assertTrue($shift->assignments()->exists());
        Notification::fake();
        $this->assertSame(500, mb_strlen(app(ReleaseShifts::class)->one($this->robin, $shift, ' '.str_repeat('é', 500).' ')->note));
    }

    public function test_the_policy_lets_an_active_person_release_a_shift_they_are_on(): void
    {
        $shift = $this->robinOn('2026-10-22');

        $this->assertTrue($this->robin->can('release', $shift));
        $this->assertFalse($this->morgan->can('release', $shift));

        $this->robin->forceFill(['deactivated_at' => now()])->save();
        $this->assertFalse($this->robin->fresh()->can('release', $shift));
    }
}
