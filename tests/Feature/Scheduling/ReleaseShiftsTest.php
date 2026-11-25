<?php

namespace Tests\Feature\Scheduling;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\AddRegular;
use App\Actions\Scheduling\CancelShift;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\GenerateShifts;
use App\Actions\Scheduling\ReleaseShifts;
use App\Actions\Scheduling\RestoreShift;
use App\Actions\Scheduling\UpdateShift;
use App\Actions\Scheduling\UpdateWeeklySlot;
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
use App\Notifications\MadeRegularNotification;
use App\Notifications\RegularEndedNotification;
use App\Notifications\ShadowLeftNotification;
use App\Notifications\ShadowSpotNotification;
use App\Notifications\ShiftCoveredNotification;
use App\Notifications\ShiftsReleasedNotification;
use App\Notifications\ShiftTakenLeadNotification;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\FailsTheFirstEmail;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class ReleaseShiftsTest extends TestCase
{
    use FailsTheFirstEmail, InteractsWithTwoFactor, RefreshDatabase;

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
        $this->robin = User::factory()->cleared()->create(['name' => 'Robin Example']);
        $this->morgan = User::factory()->cleared()->create(['name' => 'Morgan Example']);
        $this->sam = User::factory()->cleared()->create(['name' => 'Sam Example']);
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
                    && str_contains($this->text($mail), 'Robin E. can\'t make their Front desk shift. Can you take it?')
                    && str_contains($this->text($mail), 'Note from Robin E.: The key is in the drawer.');
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

    /** Sam as the regular for Front desk on Thursdays, 6:30 to 9:30pm, from October 15, with the shifts generated through Tuesday, December 8. */
    private function samsSlot(): SlotRegular
    {
        $slot = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'weekday' => 4, 'start_time' => '18:30', 'end_time' => '21:30', 'people_needed' => 1]);
        app(GenerateShifts::class)->handle(Organization::current());

        return app(AddRegular::class)->handle(null, $slot, $this->sam, '2026-10-15');
    }

    /** @return list<string> */
    private function samsDates(): array
    {
        return Shift::query()->whereHas('assignments', fn ($query) => $query->where('user_id', $this->sam->id))
            ->orderBy('starts_at')->get()->map(fn (Shift $shift) => $shift->local_date->toDateString())->all();
    }

    public function test_giving_up_a_slot_ends_the_regular_and_releases_every_future_shift(): void
    {
        $regular = $this->samsSlot();
        $released = $regular->weeklySlot->shifts()->where('local_date', '>=', '2026-10-29')->orderBy('starts_at')->pluck('id')->all();
        $this->assertCount(6, $released);
        Notification::fake();

        $shifts = app(ReleaseShifts::class)->slot($this->sam, $regular, '2026-10-29', 'Moving away.');

        $this->assertSame($released, $shifts->pluck('id')->all());
        $this->assertSame('2026-10-28', $regular->fresh()->ends_on->toDateString());
        $this->assertSame(['2026-10-15', '2026-10-22'], $this->samsDates());

        $requests = CoverRequest::query()->orderBy('id')->get();
        $this->assertSame($released, $requests->pluck('shift_id')->all());
        $this->assertTrue($requests->every(fn (CoverRequest $request) => $request->released_by === $this->sam->id && $request->note === 'Moving away.' && $request->notified_count === 3 && $request->escalated_at === null));

        $ended = ChangeLogEntry::query()->about($this->sam)->ofAction('regular.ended')->sole();
        $this->assertSame($this->sam->id, $ended->actor_id);
        $this->assertSame($regular->id, $ended->subject_id);
        $this->assertSame(['ends_on' => ['old' => null, 'new' => '2026-10-28']], $ended->changes);
        $this->assertSame($released, $ended->details['removed_from_shift_ids']);
        $this->assertSame([], $ended->details['past_shift_ids']);

        $created = ChangeLogEntry::query()->ofAction('cover_request.created')->orderBy('id')->get();
        $this->assertCount(6, $created);
        $this->assertSame($released, $created->pluck('subject_id')->all());
        $this->assertTrue($created->every(fn (ChangeLogEntry $entry) => $entry->actor_id === $this->sam->id && $entry->affected_user_id === $this->sam->id && $entry->changes['released_by'] === ['old' => null, 'new' => $this->sam->id]));

        $removed = ChangeLogEntry::query()->ofAction('assignment.removed')->get();
        $this->assertCount(6, $removed);
        $this->assertTrue($removed->every(fn (ChangeLogEntry $entry) => $entry->details['via'] === 'release:slot' && $entry->actor_id === $this->sam->id && $entry->affected_user_id === $this->sam->id));

        foreach ([$this->coordinator, $this->robin, $this->morgan] as $person) {
            Notification::assertSentToTimes($person, CoverRequestNotification::class, 1);
            Notification::assertSentTo($person, CoverRequestNotification::class, function (CoverRequestNotification $notification) use ($person) {
                $mail = $notification->toMail($person);
                $text = $this->text($mail);

                return $notification->gaveUpSlot
                    && $mail->subject === 'Can you cover Front desk on some Thursdays?'
                    && str_contains($text, 'Sam E. is giving up their weekly Thursday Front desk slot. These dates need someone:')
                    && str_contains($text, 'Front desk, Thursday, October 29, 6:30 to 9:30pm')
                    && str_contains($text, 'Front desk, Thursday, December 3, 6:30 to 9:30pm')
                    && ! str_contains($text, 'October 22')
                    && str_contains($text, 'Note from Sam E.: Moving away.');
            });
        }

        Notification::assertSentToTimes($this->sam, ShiftsReleasedNotification::class, 1);
        Notification::assertSentTo($this->sam, ShiftsReleasedNotification::class, function (ShiftsReleasedNotification $notification) {
            $mail = $notification->toMail($this->sam);
            $text = $this->text($mail);

            return $mail->subject === 'You\'re off 6 shifts'
                && str_contains($text, 'Front desk, Thursday, October 29, 6:30 to 9:30pm')
                && str_contains($text, 'You\'re no longer the Thursday regular from Thursday, October 29.')
                && ! str_contains($text, 'You stay');
        });
        Notification::assertNotSentTo($this->sam, CoverRequestNotification::class);
        Notification::assertNotSentTo($this->sam, RegularEndedNotification::class);
    }

    public function test_giving_up_a_slot_with_one_shift_left_reads_in_the_singular(): void
    {
        $regular = $this->samsSlot();
        Notification::fake();

        app(ReleaseShifts::class)->slot($this->sam, $regular, '2026-12-03', null);

        Notification::assertSentTo($this->sam, ShiftsReleasedNotification::class, function (ShiftsReleasedNotification $notification) {
            $mail = $notification->toMail($this->sam);
            $text = $this->text($mail);

            return $mail->subject === 'You\'re off Thursday, December 3'
                && str_contains($text, 'You\'re off your Front desk shift on Thursday, December 3, 6:30 to 9:30pm.')
                && str_contains($text, 'You\'re no longer the Thursday regular from Thursday, December 3.')
                && ! str_contains($text, 'You stay');
        });
        Notification::assertSentTo($this->morgan, CoverRequestNotification::class, fn (CoverRequestNotification $notification) => $notification->toMail($this->morgan)->subject === 'Can you cover Front desk on Thursday, December 3?'
            && str_contains($this->text($notification->toMail($this->morgan)), 'Sam E. is giving up their weekly Thursday Front desk slot. These dates need someone:'));
    }

    public function test_the_slot_preview_lists_the_future_shifts_and_changes_nothing(): void
    {
        $regular = $this->samsSlot();
        $slot = $regular->weeklySlot;
        $slot->shifts()->where('local_date', '2026-11-12')->update(['cancelled_at' => now(), 'cancel_reason' => 'Power outage']);
        // Sam's shadow spot on a one-time shift isn't part of the slot.
        $other = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, '2026-11-05', '10:00', '12:00', 1, 1, null);
        $this->on($other, $this->sam, AssignmentType::Shadow);
        $expected = $slot->shifts()->where('local_date', '>=', '2026-10-29')->where('local_date', '!=', '2026-11-12')->orderBy('starts_at')->pluck('id')->all();
        $before = $this->samsDates();

        $preview = app(ReleaseShifts::class)->slotPreview($this->sam, $regular, '2026-10-29');

        $this->assertSame($expected, $preview->pluck('id')->all());
        $this->assertSame('Front desk', $preview->first()->area->name);
        $this->assertNull($regular->fresh()->ends_on);
        $this->assertSame($before, $this->samsDates());
        $this->assertSame(0, CoverRequest::query()->count());
    }

    public function test_giving_up_a_slot_with_a_shift_inside_24_hours_escalates_it(): void
    {
        $lead = $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $regular = $this->samsSlot();
        $tomorrow = $regular->weeklySlot->shifts()->where('local_date', '2026-10-15')->sole();
        Notification::fake();
        // Wednesday, 7pm: Thursday's shift starts in 23 and a half hours.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 19:00', self::TIMEZONE));

        $shifts = app(ReleaseShifts::class)->slot($this->sam, $regular, '2026-10-15', null);

        $this->assertCount(8, $shifts);
        $this->assertSame([$tomorrow->id], CoverRequest::query()->whereNotNull('escalated_at')->pluck('shift_id')->all());
        $this->assertSame(1, ChangeLogEntry::query()->ofAction('cover_request.escalated')->count());

        foreach ([$lead, $this->coordinator] as $person) {
            Notification::assertSentToTimes($person, CoverUrgentNotification::class, 1);
            Notification::assertSentTo($person, CoverUrgentNotification::class, fn (CoverUrgentNotification $notification) => count($notification->shifts) === 1);
            Notification::assertSentToTimes($person, CoverRequestNotification::class, 1);
            Notification::assertSentTo($person, CoverRequestNotification::class, fn (CoverRequestNotification $notification) => count($notification->shifts) === 7
                && ! in_array('Thursday, October 15', array_column($notification->shifts, 'date'), true));
        }
        Notification::assertSentToTimes($this->morgan, CoverRequestNotification::class, 1);
        Notification::assertSentTo($this->morgan, CoverRequestNotification::class, fn (CoverRequestNotification $notification) => count($notification->shifts) === 8 && $notification->gaveUpSlot);
        Notification::assertSentToTimes($this->sam, ShiftsReleasedNotification::class, 1);
    }

    public function test_giving_up_from_today_leaves_a_started_shift_alone(): void
    {
        $regular = $this->samsSlot();
        $today = $regular->weeklySlot->shifts()->where('local_date', '2026-10-15')->sole();
        // Thursday, 7pm: today's shift started at 6:30.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 19:00', self::TIMEZONE));
        Notification::fake();

        $shifts = app(ReleaseShifts::class)->slot($this->sam, $regular, '2026-10-15', null);

        $this->assertCount(7, $shifts);
        $this->assertNotContains($today->id, $shifts->pluck('id')->all());
        $this->assertSame('2026-10-14', $regular->fresh()->ends_on->toDateString());
        $this->assertSame(['2026-10-15'], $this->samsDates());
        $this->assertFalse(CoverRequest::query()->where('shift_id', $today->id)->exists());
        $this->assertNotContains($today->id, ChangeLogEntry::query()->ofAction('regular.ended')->sole()->details['removed_from_shift_ids']);
        Notification::assertSentTo($this->sam, ShiftsReleasedNotification::class, fn (ShiftsReleasedNotification $notification) => count($notification->shifts) === 7);
    }

    public function test_giving_up_a_slot_with_no_shifts_on_the_calendar_from_that_date(): void
    {
        $regular = $this->samsSlot();
        Notification::fake();

        // Shifts are on the calendar through Tuesday, December 8.
        $shifts = app(ReleaseShifts::class)->slot($this->sam, $regular, '2026-12-10', null);

        $this->assertCount(0, $shifts);
        $this->assertSame('2026-12-09', $regular->fresh()->ends_on->toDateString());
        $this->assertSame(1, ChangeLogEntry::query()->about($this->sam)->ofAction('regular.ended')->count());
        $this->assertSame(0, CoverRequest::query()->count());
        Notification::assertNotSentTo([$this->coordinator, $this->robin, $this->morgan], CoverRequestNotification::class);
        Notification::assertNotSentTo($this->sam, RegularEndedNotification::class);
        Notification::assertSentToTimes($this->sam, ShiftsReleasedNotification::class, 1);
        Notification::assertSentTo($this->sam, ShiftsReleasedNotification::class, function (ShiftsReleasedNotification $notification) {
            $mail = $notification->toMail($this->sam);
            $text = $this->text($mail);

            return $mail->subject === 'You\'re no longer the Thursday regular'
                && str_contains($text, 'You\'re no longer the Thursday regular from Thursday, December 10.')
                && ! str_contains($text, 'You\'re off');
        });
    }

    public function test_giving_up_a_slot_refuses_someone_elses_slot_and_a_past_date(): void
    {
        $regular = $this->samsSlot();
        Notification::fake();

        foreach ([[$this->robin, '2026-10-29', 'That isn\'t your weekly slot.'], [$this->sam, '2026-10-13', 'Pick a date from today on.']] as [$person, $from, $message]) {
            try {
                app(ReleaseShifts::class)->slot($person, $regular, $from, null);
                $this->fail('Expected a refusal for '.$from);
            } catch (DomainException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }

        $this->assertNull($regular->fresh()->ends_on);
        $this->assertSame(0, CoverRequest::query()->count());
        Notification::assertNothingSent();
    }

    public function test_slot_refuses_an_unreadable_date(): void
    {
        $regular = $this->samsSlot();
        $before = $this->samsDates();
        Notification::fake();

        foreach (['soon', '2026-10-32', '10/29/2026', '2026-10-29 junk', ''] as $from) {
            try {
                app(ReleaseShifts::class)->slot($this->sam, $regular, $from, null);
                $this->fail('Expected a refusal for "'.$from.'"');
            } catch (DomainException $exception) {
                $this->assertSame('Pick a date.', $exception->getMessage());
            }
        }

        $this->assertNull($regular->fresh()->ends_on);
        $this->assertSame($before, $this->samsDates());
        $this->assertSame(0, CoverRequest::query()->count());
        $this->assertSame(0, ChangeLogEntry::query()->ofAction('regular.ended')->count());
        Notification::assertNothingSent();
    }

    public function test_slot_preview_refuses_an_unreadable_date(): void
    {
        $regular = $this->samsSlot();

        $this->expectExceptionObject(new DomainException('Pick a date.'));

        app(ReleaseShifts::class)->slotPreview($this->sam, $regular, 'next Thursday');
    }

    public function test_slot_preview_refuses_a_past_date(): void
    {
        $regular = $this->samsSlot();

        try {
            app(ReleaseShifts::class)->slotPreview($this->sam, $regular, '2026-10-13');
            $this->fail('Expected a refusal.');
        } catch (DomainException $exception) {
            $this->assertSame('Pick a date from today on.', $exception->getMessage());
        }

        // Today is fine.
        $this->assertCount(8, app(ReleaseShifts::class)->slotPreview($this->sam, $regular, '2026-10-14'));
        $this->assertNull($regular->fresh()->ends_on);
    }

    public function test_shadows_are_told_only_when_nobody_but_shadows_is_left(): void
    {
        $casey = User::factory()->cleared()->create(['name' => 'Casey Example']);
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
            && str_contains($this->text($notification->toMail($casey)), 'Morgan E. can\'t make their Front desk shift on Thursday, October 22'));
        Notification::assertNotSentTo($casey, CoverRequestNotification::class);
        Notification::assertSentTo($this->morgan, ShiftsReleasedNotification::class, fn (ShiftsReleasedNotification $notification) => str_contains($this->text($notification->toMail($this->morgan)), 'Casey E. was going to shadow you, so we\'ve told them too.'));
    }

    public function test_a_shadow_on_several_released_dates_gets_one_email(): void
    {
        $casey = User::factory()->cleared()->create(['name' => 'Casey Example']);
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
                && str_contains($text, 'Robin E. can\'t make these shifts, so right now there\'s nobody for you to shadow on them:')
                && str_contains($text, 'Front desk, Thursday, October 22, 6:30 to 9:30pm')
                && str_contains($text, 'Front desk, Thursday, October 29, 6:30 to 9:30pm');
        });
        Notification::assertSentTo($this->robin, ShiftsReleasedNotification::class, fn (ShiftsReleasedNotification $notification) => str_contains($this->text($notification->toMail($this->robin)), 'Casey E. was going to shadow you on these dates, so we\'ve told them too.'));
    }

    public function test_releasing_inside_24_hours_escalates_at_once_and_only_once(): void
    {
        $lead = $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $casey = User::factory()->cleared()->create(['name' => 'Casey Example']);
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

    public function test_a_lead_who_could_cover_gets_only_the_urgent_email(): void
    {
        $lead = $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $shift = $this->robinOn('2026-10-15');
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 19:00', self::TIMEZONE));

        $request = app(ReleaseShifts::class)->one($this->robin, $shift, null);

        // Morgan and Sam are asked; Alex and Chris get the urgent email instead.
        $this->assertSame(2, $request->fresh()->notified_count);
        foreach ([$lead, $this->coordinator] as $person) {
            Notification::assertSentToTimes($person, CoverUrgentNotification::class, 1);
            Notification::assertNotSentTo($person, CoverRequestNotification::class);
        }
        Notification::assertSentTo($lead, CoverUrgentNotification::class, fn (CoverUrgentNotification $notification) => str_contains($this->text($notification->toMail($lead)), 'We emailed 2 volunteers when it was released.'));
        foreach ([$this->morgan, $this->sam] as $person) {
            Notification::assertSentToTimes($person, CoverRequestNotification::class, 1);
        }
    }

    public function test_bare_release_locked_inside_24_hours_keeps_leads_as_recipients(): void
    {
        $lead = $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $shift = $this->robinOn('2026-10-15');
        $this->travelTo(CarbonImmutable::parse('2026-10-14 19:00', self::TIMEZONE));

        $request = DB::transaction(function () use ($shift) {
            Area::query()->whereKey($shift->area_id)->lockForUpdate()->firstOrFail();
            $locked = Shift::query()->with('area')->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            $assignment = Assignment::query()->with('user')->where('shift_id', $locked->id)->where('user_id', $this->robin->id)->lockForUpdate()->firstOrFail();

            return app(ReleaseShifts::class)->releaseLocked($this->robin, $locked, $assignment, null, 'one');
        });

        $ids = $request->getRelation('recipients')->pluck('id')->all();
        $this->assertContains($lead->id, $ids);
        $this->assertContains($this->coordinator->id, $ids);
        $this->assertSame(4, $request->notified_count);
        $this->assertSame(4, $request->fresh()->notified_count);
        $this->assertNull($request->fresh()->escalated_at);
    }

    public function test_a_range_with_two_shifts_inside_24_hours_sends_one_urgent_email_per_person(): void
    {
        $lead = $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $casey = User::factory()->cleared()->create(['name' => 'Casey Example']);
        $morning = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->hostedEvents, '2026-10-15', '10:00', '12:00', 1, 1, null);
        $this->on($morning, $this->robin);
        $evening = $this->robinOn('2026-10-15');
        $this->on($morning, $casey, AssignmentType::Shadow);
        $this->on($evening, $casey, AssignmentType::Shadow);
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 19:00', self::TIMEZONE));

        app(ReleaseShifts::class)->range($this->robin, '2026-10-14', '2026-10-15', null);

        $this->assertSame(2, CoverRequest::query()->whereNotNull('escalated_at')->count());
        $this->assertSame(2, ChangeLogEntry::query()->ofAction('cover_request.escalated')->count());

        Notification::assertSentToTimes($this->coordinator, CoverUrgentNotification::class, 1);
        Notification::assertSentTo($this->coordinator, CoverUrgentNotification::class, function (CoverUrgentNotification $notification) {
            $mail = $notification->toMail($this->coordinator);
            $text = $this->text($mail);

            return count($notification->shifts) === 2
                && $mail->subject === 'Needs someone tomorrow: 2 shifts'
                && str_contains($text, 'Nobody has taken these shifts yet, and they start in less than 24 hours.')
                && str_contains($text, 'Hosted events, Thursday, October 15, 10am to 12pm')
                && str_contains($text, 'Front desk, Thursday, October 15, 6:30 to 9:30pm')
                && str_contains($text, 'You\'re getting this because you\'re a coordinator.');
        });
        Notification::assertNotSentTo($this->coordinator, CoverRequestNotification::class);
        Notification::assertSentToTimes($lead, CoverUrgentNotification::class, 1);
        Notification::assertSentTo($lead, CoverUrgentNotification::class, fn (CoverUrgentNotification $notification) => array_column($notification->shifts, 'area') === ['Front desk']);
        Notification::assertSentToTimes($lead, CoverRequestNotification::class, 1);
        Notification::assertSentTo($lead, CoverRequestNotification::class, fn (CoverRequestNotification $notification) => array_column($notification->shifts, 'area') === ['Hosted events']);

        Notification::assertSentToTimes($casey, ShadowSpotNotification::class, 1);
        Notification::assertSentTo($casey, ShadowSpotNotification::class, function (ShadowSpotNotification $notification) use ($casey) {
            $mail = $notification->toMail($casey);

            return $notification->notice === ShadowSpotNotice::StillUncovered
                && $mail->subject === 'Your shadow shifts tomorrow still have nobody to shadow'
                && count($notification->shifts) === 2
                && str_contains($this->text($mail), 'Alex L. (Shift lead) knows.');
        });
        Notification::assertSentToTimes($this->morgan, CoverRequestNotification::class, 1);
        Notification::assertSentTo($this->morgan, CoverRequestNotification::class, fn (CoverRequestNotification $notification) => count($notification->shifts) === 2);
    }

    public function test_releasing_inside_24_hours_doesnt_escalate_a_shift_that_still_has_its_people(): void
    {
        $shift = $this->robinOn('2026-10-15');
        $this->on($shift, $this->morgan);
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 19:00', self::TIMEZONE));

        $request = app(ReleaseShifts::class)->one($this->robin, $shift, null);

        $this->assertNull($request);
        $this->assertSame(0, CoverRequest::query()->count());
        $this->assertSame(0, ChangeLogEntry::query()->ofAction('cover_request.escalated')->count());
        Notification::assertNotSentTo($this->coordinator, CoverUrgentNotification::class);
        Notification::assertNotSentTo($this->coordinator, CoverRequestNotification::class);
    }

    public function test_releasing_from_a_shift_that_still_has_its_people_asks_nobody(): void
    {
        $shift = $this->robinOn('2026-10-22');
        $this->on($shift, $this->morgan);
        Notification::fake();

        $releaseShifts = app(ReleaseShifts::class);
        $request = $releaseShifts->one($this->robin, $shift, 'Sorry.');

        $this->assertNull($request);
        $this->assertSame([$shift->id], $releaseShifts->nobodyAsked);
        $this->assertFalse(Assignment::query()->where('shift_id', $shift->id)->where('user_id', $this->robin->id)->exists());
        $this->assertSame(0, CoverRequest::query()->count());
        $this->assertSame(0, ChangeLogEntry::query()->ofAction('cover_request.created')->count());
        $removed = ChangeLogEntry::query()->ofAction('assignment.removed')->sole();
        $this->assertSame('release:one', $removed->details['via']);
        $this->assertSame($this->robin->id, $removed->affected_user_id);
        Notification::assertNotSentTo([$this->coordinator, $this->sam, $this->morgan], CoverRequestNotification::class);
        Notification::assertSentToTimes($this->robin, ShiftsReleasedNotification::class, 1);
        Notification::assertSentTo($this->robin, ShiftsReleasedNotification::class, function (ShiftsReleasedNotification $notification) {
            $text = $this->text($notification->toMail($this->robin));

            return str_contains($text, 'You\'re off your Front desk shift on Thursday, October 22, 6:30 to 9:30pm.')
                && str_contains($text, 'Front desk still has the people it needs, so nobody else was asked.')
                && ! str_contains($text, 'We\'ve asked')
                && ! str_contains($text, 'take it back');
        });

        // The ladder never sees a request there.
        $this->travelTo(CarbonImmutable::parse('2026-10-21 19:00', self::TIMEZONE));
        $this->assertSame(['resent' => 0, 'escalated' => 0], app(CoverLadder::class)->run());
    }

    public function test_a_range_says_which_shifts_still_have_their_people(): void
    {
        $full = $this->robinOn('2026-10-22');
        $this->on($full, $this->morgan);
        $this->robinOn('2026-10-23');
        Notification::fake();

        app(ReleaseShifts::class)->range($this->robin, '2026-10-22', '2026-10-23', null);

        $this->assertSame(1, CoverRequest::query()->count());
        Notification::assertSentTo($this->sam, CoverRequestNotification::class, fn (CoverRequestNotification $notification) => array_column($notification->shifts, 'date') === ['Friday, October 23']);
        Notification::assertSentTo($this->robin, ShiftsReleasedNotification::class, function (ShiftsReleasedNotification $notification) {
            $text = $this->text($notification->toMail($this->robin));

            return str_contains($text, 'Front desk still has the people it needs on Thursday, October 22, so nobody else was asked.')
                && str_contains($text, 'We\'ve asked other volunteers to cover the rest, and we\'ll email you when someone does.');
        });
    }

    public function test_a_coordinator_adding_a_regular_fills_the_slots_open_requests(): void
    {
        $regular = $this->samsSlot();
        $lead = $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        app(ReleaseShifts::class)->slot($this->sam, $regular, '2026-10-29', null);
        $requests = CoverRequest::query()->orderBy('id')->get();
        $this->assertCount(6, $requests);
        Notification::fake();

        $added = app(AddRegular::class)->handle($this->coordinator, $regular->weeklySlot, $this->morgan, '2026-10-29');

        $this->assertSame(6, $added->getAttribute('added_to_shifts'));
        foreach ($requests as $request) {
            $request->refresh();
            $this->assertSame($this->morgan->id, $request->filled_by);
            $this->assertNotNull($request->filled_at);
        }

        $entries = ChangeLogEntry::query()->ofAction('cover_request.filled')->orderBy('id')->get();
        $this->assertCount(6, $entries);
        foreach ($entries as $entry) {
            $this->assertSame($this->coordinator->id, $entry->actor_id);
            $this->assertSame($this->morgan->id, $entry->affected_user_id);
            $this->assertSame(['filled_at', 'filled_by'], collect(array_keys($entry->changes))->sort()->values()->all());
            $this->assertSame($this->morgan->id, $entry->changes['filled_by']['new']);
        }

        // The releaser gets one email listing every date; the leads aren't told, since a coordinator did it. The new regular gets the usual email.
        Notification::assertSentToTimes($this->sam, ShiftCoveredNotification::class, 1);
        Notification::assertSentTo($this->sam, ShiftCoveredNotification::class, fn (ShiftCoveredNotification $notification) => count($notification->dates) === 6 && $notification->takerFirstName === 'Morgan E.');
        Notification::assertNotSentTo($lead, ShiftTakenLeadNotification::class);
        Notification::assertSentToTimes($this->morgan, MadeRegularNotification::class, 1);

        // The ladder has nothing left to send for those dates.
        $this->travelTo(CarbonImmutable::parse('2026-10-28 19:00', self::TIMEZONE));
        $this->assertSame(['resent' => 0, 'escalated' => 0], app(CoverLadder::class)->run());
    }

    public function test_bringing_a_shift_back_with_its_regulars_fills_its_request(): void
    {
        $regular = $this->samsSlot();
        app(ReleaseShifts::class)->slot($this->sam, $regular, '2026-10-22', null);
        $shift = Shift::query()->where('weekly_slot_id', $regular->weekly_slot_id)->where('local_date', '2026-10-22')->sole();
        $request = CoverRequest::query()->where('shift_id', $shift->id)->sole();
        app(CancelShift::class)->handle($this->coordinator, $shift, 'Snow day');
        app(AddRegular::class)->handle($this->coordinator, $regular->weeklySlot, $this->morgan, '2026-10-22');
        $this->assertNull($request->fresh()->filled_at);
        Notification::fake();

        app(RestoreShift::class)->handle($this->coordinator, $shift->fresh());

        $this->assertTrue($shift->assignments()->where('user_id', $this->morgan->id)->exists());
        $request->refresh();
        $this->assertSame($this->morgan->id, $request->filled_by);
        $entry = ChangeLogEntry::query()->ofAction('cover_request.filled')->where('subject_id', $shift->id)->sole();
        $this->assertSame($this->coordinator->id, $entry->actor_id);
        $this->assertSame($this->morgan->id, $entry->affected_user_id);
        $this->assertSame(['filled_at', 'filled_by'], collect(array_keys($entry->changes))->sort()->values()->all());
        Notification::assertSentToTimes($this->sam, ShiftCoveredNotification::class, 1);
        Notification::assertSentTo($this->sam, ShiftCoveredNotification::class, fn (ShiftCoveredNotification $notification) => $notification->dates === ['Thursday, October 22']);
    }

    public function test_a_slot_change_bringing_a_date_back_fills_its_request(): void
    {
        $regular = $this->samsSlot();
        $slot = $regular->weeklySlot;
        app(ReleaseShifts::class)->slot($this->sam, $regular, '2026-10-22', null);
        $shift = Shift::query()->where('weekly_slot_id', $slot->id)->where('local_date', '2026-10-22')->sole();
        $request = CoverRequest::query()->where('shift_id', $shift->id)->sole();
        $attributes = fn (WeeklySlot $slot) => [
            'weekday' => $slot->weekday, 'start_time' => substr($slot->start_time, 0, 5), 'end_time' => substr($slot->end_time, 0, 5),
            'people_needed' => $slot->people_needed, 'shadow_spots' => $slot->shadow_spots, 'frequency' => $slot->frequency->value,
            'valid_from' => $slot->valid_from->toDateString(), 'valid_until' => $slot->valid_until?->toDateString(),
            'title' => $slot->title, 'is_rotation' => $slot->is_rotation,
        ];

        // The slot stops running before October 22, then runs again, bringing that date back with the regulars working it.
        app(UpdateWeeklySlot::class)->handle($this->coordinator, $slot, ['valid_until' => '2026-10-21'] + $attributes($slot));
        $this->assertNotNull($shift->fresh()->cancelled_at);
        // The slot doesn't run on that date right now, which AddRegular would refuse.
        SlotRegular::query()->create(['weekly_slot_id' => $slot->id, 'user_id' => $this->robin->id, 'frequency' => 'weekly', 'starts_on' => '2026-10-22', 'ends_on' => '2026-10-22']);
        Notification::fake();

        app(UpdateWeeklySlot::class)->handle($this->coordinator, $slot->fresh(), ['valid_until' => null] + $attributes($slot->fresh()));

        $this->assertNull($shift->fresh()->cancelled_at);
        $this->assertTrue($shift->assignments()->where('user_id', $this->robin->id)->exists());
        $this->assertSame($this->robin->id, $request->fresh()->filled_by);
        $entry = ChangeLogEntry::query()->ofAction('cover_request.filled')->where('subject_id', $shift->id)->sole();
        $this->assertSame($this->robin->id, $entry->affected_user_id);
        Notification::assertSentTo($this->sam, ShiftCoveredNotification::class, fn (ShiftCoveredNotification $notification) => $notification->dates === ['Thursday, October 22'] && $notification->takerFirstName === 'Robin E.');
    }

    public function test_one_failed_email_doesnt_stop_the_others_after_a_release(): void
    {
        $shift = $this->robinOn('2026-10-22');
        $sent = $this->failTheFirstEmail();

        // Robin's own email goes first and fails.
        app(ReleaseShifts::class)->one($this->robin, $shift, null);

        $this->assertTheFailureWasReported();
        $this->assertNotContains([$this->robin->id, ShiftsReleasedNotification::class], $sent->getArrayCopy());
        foreach ([$this->coordinator, $this->morgan, $this->sam] as $person) {
            $this->assertContains([$person->id, CoverRequestNotification::class], $sent->getArrayCopy());
        }
    }

    public function test_a_lead_releasing_inside_24_hours_doesnt_get_the_urgent_email_about_it(): void
    {
        $lead = $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, '2026-10-15', '18:30', '21:30', 1, 1, null);
        $this->on($shift, $lead);
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 19:00', self::TIMEZONE));

        $request = app(ReleaseShifts::class)->one($lead, $shift, null);

        $this->assertNotNull($request->fresh()->escalated_at);
        Notification::assertNotSentTo($lead, CoverUrgentNotification::class);
        Notification::assertNotSentTo($lead, CoverRequestNotification::class);
        Notification::assertSentToTimes($lead, ShiftsReleasedNotification::class, 1);
        Notification::assertSentToTimes($this->coordinator, CoverUrgentNotification::class, 1);
    }

    public function test_releasing_inside_24_hours_after_people_needed_was_lowered_doesnt_crash(): void
    {
        $lead = $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $casey = User::factory()->cleared()->create(['name' => 'Casey Example']);
        $shift = $this->robinOn('2026-10-15', peopleNeeded: 2);
        $this->on($shift, $this->morgan);
        $this->on($shift, $casey, AssignmentType::Shadow);
        $this->travelTo(CarbonImmutable::parse('2026-10-14 19:00', self::TIMEZONE));

        // Robin releases inside 24 hours, so the request is escalated at once.
        $robins = app(ReleaseShifts::class)->one($this->robin, $shift, null);
        $this->assertNotNull($robins->fresh()->escalated_at);

        // A coordinator lowers people needed to 1. Robin's escalated request stays open.
        app(UpdateShift::class)->handle($this->coordinator, $shift->fresh(), ['people_needed' => 1]);
        Notification::fake();

        $morgans = app(ReleaseShifts::class)->one($this->morgan, $shift->fresh(), null);

        $this->assertFalse(Assignment::query()->where('shift_id', $shift->id)->where('user_id', $this->morgan->id)->exists());
        $this->assertNotNull($morgans);
        $this->assertSame($this->morgan->id, ChangeLogEntry::query()->ofAction('assignment.removed')->latest('id')->first()->affected_user_id);
        $this->assertTrue(ChangeLogEntry::query()->ofAction('cover_request.created')->where('details->cover_request_id', $morgans->id)->exists());
        $this->assertSame(1, ChangeLogEntry::query()->ofAction('cover_request.escalated')->count());

        // The leads and coordinators already had the urgent email; nobody gets it again.
        Notification::assertNotSentTo([$lead, $this->coordinator], CoverUrgentNotification::class);
        // Morgan was the last regular, so the shadow hears now that there's nobody to shadow, once, and not "on hold" as well.
        Notification::assertSentToTimes($casey, ShadowSpotNotification::class, 1);
        Notification::assertSentTo($casey, ShadowSpotNotification::class, fn (ShadowSpotNotification $notification) => $notification->notice === ShadowSpotNotice::StillUncovered
            && str_contains($this->text($notification->toMail($casey)), 'Alex L. (Shift lead) knows.'));
        Notification::assertSentToTimes($this->morgan, ShiftsReleasedNotification::class, 1);
    }

    public function test_the_releaser_email_lists_shadow_spots_left_in_the_same_range(): void
    {
        $this->robinOn('2026-10-22');
        $shadowed = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, '2026-10-29', '18:30', '21:30', 1, 1, null);
        $this->on($shadowed, $this->morgan);
        $this->on($shadowed, $this->robin, AssignmentType::Shadow);
        Notification::fake();

        app(ReleaseShifts::class)->range($this->robin, '2026-10-20', '2026-10-31', null);

        Notification::assertSentToTimes($this->robin, ShiftsReleasedNotification::class, 1);
        Notification::assertSentTo($this->robin, ShiftsReleasedNotification::class, function (ShiftsReleasedNotification $notification) {
            $mail = $notification->toMail($this->robin);
            $text = $this->text($mail);

            return $mail->subject === 'You\'re off 2 shifts'
                && str_contains($text, 'Front desk, Thursday, October 22, 6:30 to 9:30pm')
                && str_contains($text, 'Thursday, October 29: shadow spot on Front desk');
        });
        Notification::assertSentToTimes($this->coordinator, ShadowLeftNotification::class, 1);
        Notification::assertSentTo($this->coordinator, ShadowLeftNotification::class, fn (ShadowLeftNotification $notification) => count($notification->shifts) === 1);
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

        $this->assertNull(app(ReleaseShifts::class)->one($this->robin, $shift, 'Sorry, [car](/trouble) trouble!'));

        $this->assertDatabaseMissing('assignments', ['id' => $assignment->id]);
        $this->assertSame(0, CoverRequest::query()->count());
        foreach ([$this->coordinator, $otherCoordinator] as $person) {
            Notification::assertSentToTimes($person, ShadowLeftNotification::class, 1);
            Notification::assertSentTo($person, ShadowLeftNotification::class, function (ShadowLeftNotification $notification) use ($person, $shift) {
                $mail = $notification->toMail($person);
                $text = $this->text($mail);

                return $mail->subject === 'Robin E. can\'t make their shadow shift'
                    && str_contains(html_entity_decode($text), 'Note from Robin E.: Sorry, [car](/trouble) trouble!')
                    && ! str_contains((string) $mail->render(), 'href="/trouble"')
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
