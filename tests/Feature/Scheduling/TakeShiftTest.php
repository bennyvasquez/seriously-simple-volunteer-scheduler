<?php

namespace Tests\Feature\Scheduling;

use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\RemovePerson;
use App\Actions\Scheduling\AddRegular;
use App\Actions\Scheduling\CancelShift;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\GenerateShifts;
use App\Actions\Scheduling\PutOnShift;
use App\Actions\Scheduling\ReleaseShifts;
use App\Actions\Scheduling\ShiftAlreadyTaken;
use App\Actions\Scheduling\TakeShift;
use App\ChangeLog\Describe;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Enums\ShadowSpotNotice;
use App\Enums\SlotFrequency;
use App\Enums\TakeHow;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\CoverRequest;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Models\WeeklySlot;
use App\Notifications\MadeRegularNotification;
use App\Notifications\PutOnShiftNotification;
use App\Notifications\ShadowSpotNotification;
use App\Notifications\ShiftCoveredNotification;
use App\Notifications\ShiftTakenLeadNotification;
use App\Notifications\ShiftTakenNotification;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\FailsTheFirstEmail;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class TakeShiftTest extends TestCase
{
    use FailsTheFirstEmail, InteractsWithTwoFactor, RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private Area $frontDesk;

    private Area $hostedEvents;

    private User $coordinator;

    private User $lead;

    private User $robin;

    private User $jordan;

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
        $this->lead = $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $this->robin = User::factory()->cleared()->create(['name' => 'Robin Example']);
        $this->jordan = User::factory()->cleared()->create(['name' => 'Jordan Example']);
        $this->sam = User::factory()->cleared()->create(['name' => 'Sam Example']);
    }

    private function withRole(string $name, Role $role, ?Area $area = null): User
    {
        $user = User::factory()->create(['name' => $name]);
        app(GrantRole::class)->handle(null, $user, $role, $area);
        $this->enableTwoFactor($user);

        return $user;
    }

    /** A 6:30 to 9:30pm one-time shift. */
    private function shift(string $date, ?Area $area = null, int $peopleNeeded = 1, string $from = '18:30', string $to = '21:30'): Shift
    {
        return app(CreateOneTimeShift::class)->handle($this->coordinator, $area ?? $this->frontDesk, $date, $from, $to, $peopleNeeded, 1, null);
    }

    private function on(Shift $shift, User $person, AssignmentType $type = AssignmentType::Regular): void
    {
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $person->id, 'type' => $type]);
    }

    /** Robin on a shift, then released with a note. */
    private function released(string $date = '2026-10-22', int $peopleNeeded = 1): Shift
    {
        $shift = $this->shift($date, peopleNeeded: $peopleNeeded);
        $this->on($shift, $this->robin);
        app(ReleaseShifts::class)->one($this->robin, $shift, 'The key is in the drawer.');

        return $shift->fresh();
    }

    /** A weekly Front desk slot on Thursdays, 6:30 to 9:30pm, with its shifts generated. */
    private function thursdaySlot(array $attributes = []): WeeklySlot
    {
        $slot = WeeklySlot::factory()->create($attributes + ['area_id' => $this->frontDesk->id, 'weekday' => 4, 'start_time' => '18:30', 'end_time' => '21:30', 'people_needed' => 1]);
        app(GenerateShifts::class)->handle(Organization::current());

        return $slot;
    }

    private function text(MailMessage $mail): string
    {
        return implode("\n", array_merge([$mail->greeting], $mail->introLines, $mail->outroLines));
    }

    /** @return list<string> */
    private function datesFor(User $user): array
    {
        return Shift::query()->whereHas('assignments', fn ($query) => $query->where('user_id', $user->id))
            ->orderBy('starts_at')->get()->map(fn (Shift $shift) => $shift->local_date->toDateString())->all();
    }

    private function assertRefused(string $message, User $person, Shift $shift, TakeHow $how = TakeHow::JustThisDate): void
    {
        try {
            app(TakeShift::class)->handle($person, $shift, $how);
            $this->fail('Taking it should be refused.');
        } catch (DomainException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function test_the_emails_say_someone_when_the_releaser_has_been_removed(): void
    {
        $casey = User::factory()->cleared()->create(['name' => 'Casey Example']);
        $shift = $this->shift('2026-10-22');
        $this->on($shift, $this->robin);
        $this->on($shift, $casey, AssignmentType::Shadow);
        app(ReleaseShifts::class)->one($this->robin, $shift, 'The key is in the drawer.');
        app(RemovePerson::class)->handle($this->coordinator, $this->robin);
        $this->assertSame('Someone', CoverRequest::query()->sole()->releaserName());
        Notification::fake();

        app(TakeShift::class)->handle($this->jordan, $shift->fresh(), TakeHow::JustThisDate);

        Notification::assertSentTo($this->jordan, ShiftTakenNotification::class, fn (ShiftTakenNotification $notification) => $notification->releaserFirstName === 'Someone'
            && str_contains($this->text($notification->toMail($this->jordan)), 'Note from Someone: The key is in the drawer.'));
        Notification::assertSentTo($this->lead, ShiftTakenLeadNotification::class, fn (ShiftTakenLeadNotification $notification) => str_contains($this->text($notification->toMail($this->lead)), 'Jordan E. is covering Someone\'s Front desk shift'));
        Notification::assertSentTo($casey, ShadowSpotNotification::class, fn (ShadowSpotNotification $notification) => $notification->notice === ShadowSpotNotice::BackOn && $notification->releaserFirstName === 'Someone');
    }

    public function test_taking_a_released_shift_fills_its_cover_request(): void
    {
        $casey = User::factory()->cleared()->create(['name' => 'Casey Example']);
        $shift = $this->shift('2026-10-22');
        $this->on($shift, $this->robin);
        $this->on($shift, $casey, AssignmentType::Shadow);
        app(ReleaseShifts::class)->one($this->robin, $shift, 'The key is in the drawer.');
        $request = CoverRequest::query()->sole();
        Notification::fake();

        $assignment = app(TakeShift::class)->handle($this->jordan, $shift->fresh(), TakeHow::JustThisDate);

        $this->assertSame($this->jordan->id, $assignment->user_id);
        $this->assertSame(AssignmentType::Regular, $assignment->fresh()->type);
        $request->refresh();
        $this->assertSame($this->jordan->id, $request->filled_by);
        $this->assertNotNull($request->filled_at);

        $added = ChangeLogEntry::query()->forSubject($shift)->ofAction('assignment.added')->latest('id')->first();
        $this->assertSame($this->jordan->id, $added->actor_id);
        $this->assertSame($this->jordan->id, $added->affected_user_id);
        $this->assertSame(['assignment_id' => $assignment->id, 'type' => 'regular', 'via' => 'take'], $added->details);
        $this->assertSame('Took the Front desk shift on Thursday, October 22, 6:30 to 9:30pm', Describe::entry($added, $this->coordinator));

        $filled = ChangeLogEntry::query()->forSubject($shift)->ofAction('cover_request.filled')->sole();
        $this->assertSame($this->jordan->id, $filled->actor_id);
        $this->assertSame($this->jordan->id, $filled->affected_user_id);
        $this->assertSame(['cover_request_id' => $request->id], $filled->details);
        $this->assertSame(['filled_by', 'filled_at'], array_keys($filled->changes));
        $this->assertSame(['old' => null, 'new' => $this->jordan->id], $filled->changes['filled_by']);
        $this->assertNull($filled->changes['filled_at']['old']);
        $this->assertSame('Cover request for Front desk, Thursday, October 22 filled by Jordan Example', Describe::entry($filled, $this->coordinator));

        Notification::assertSentToTimes($this->jordan, ShiftTakenNotification::class, 1);
        Notification::assertSentTo($this->jordan, ShiftTakenNotification::class, function (ShiftTakenNotification $notification) {
            $mail = $notification->toMail($this->jordan);
            $text = $this->text($mail);

            return $mail->subject === 'You\'re on Front desk, Thursday, October 22'
                && str_contains($text, 'Hi Jordan, thanks for covering!')
                && str_contains($text, 'Thursday, October 22, 6:30 to 9:30pm, Front desk.')
                && str_contains($text, 'Note from Robin E.: The key is in the drawer.')
                && str_contains($text, 'Casey E. is shadowing.');
        });

        Notification::assertSentToTimes($this->robin, ShiftCoveredNotification::class, 1);
        Notification::assertSentTo($this->robin, ShiftCoveredNotification::class, function (ShiftCoveredNotification $notification) {
            $mail = $notification->toMail($this->robin);
            $text = $this->text($mail);

            return $mail->subject === 'Jordan E. is covering your Thursday, October 22 shift'
                && str_contains($text, 'Hi Robin,')
                && str_contains($text, 'Jordan E. took your Front desk shift on Thursday, October 22. You don\'t need to do anything.');
        });

        Notification::assertSentToTimes($this->lead, ShiftTakenLeadNotification::class, 1);
        Notification::assertSentTo($this->lead, ShiftTakenLeadNotification::class, function (ShiftTakenLeadNotification $notification) use ($shift) {
            $mail = $notification->toMail($this->lead);

            return $mail->subject === 'Covered: Front desk on Thursday, October 22'
                && str_contains($this->text($mail), 'Jordan E. is covering Robin E.\'s Front desk shift on Thursday, October 22, 6:30 to 9:30pm.')
                && $mail->actionText === 'Open the shift'
                && $mail->actionUrl === route('calendar.shift', $shift);
        });

        Notification::assertSentToTimes($casey, ShadowSpotNotification::class, 1);
        Notification::assertSentTo($casey, ShadowSpotNotification::class, fn (ShadowSpotNotification $notification) => $notification->notice === ShadowSpotNotice::BackOn
            && $notification->toMail($casey)->subject === 'Your shadow shift on Thursday, October 22 is back on'
            && str_contains($this->text($notification->toMail($casey)), 'Jordan E. is covering, so you\'ll shadow Jordan E.'));

        Notification::assertNothingSentTo($this->coordinator);
        Notification::assertNothingSentTo($this->sam);
    }

    public function test_one_failed_email_doesnt_stop_the_others_after_a_take(): void
    {
        $shift = $this->released();
        $sent = $this->failTheFirstEmail();

        // The taker's own email goes first and fails.
        app(TakeShift::class)->handle($this->jordan, $shift, TakeHow::JustThisDate);

        $this->assertTheFailureWasReported();
        $this->assertNotContains([$this->jordan->id, ShiftTakenNotification::class], $sent->getArrayCopy());
        $this->assertContains([$this->robin->id, ShiftCoveredNotification::class], $sent->getArrayCopy());
        $this->assertContains([$this->lead->id, ShiftTakenLeadNotification::class], $sent->getArrayCopy());
    }

    public function test_two_people_taking_the_last_place_at_once_only_one_gets_it(): void
    {
        $shift = $this->released();
        // Loaded with its assignments before Jordan takes it, the way Sam's request would have read it, so a check on the passed model would see the place still open. Only the locked re-read can refuse Sam.
        $stale = Shift::query()->with('assignments')->findOrFail($shift->id);
        Notification::fake();

        app(TakeShift::class)->handle($this->jordan, $shift, TakeHow::JustThisDate);

        try {
            app(TakeShift::class)->handle($this->sam, $stale, TakeHow::JustThisDate);
            $this->fail('The second person should be refused.');
        } catch (ShiftAlreadyTaken $e) {
            $this->assertSame('Someone already took this one.', $e->getMessage());
        }

        $this->assertSame([$this->jordan->id], $shift->assignments()->pluck('user_id')->all());
        $this->assertSame(1, ChangeLogEntry::query()->ofAction('cover_request.filled')->count());
        $this->assertSame($this->jordan->id, CoverRequest::query()->sole()->filled_by);
        Notification::assertSentToTimes($this->robin, ShiftCoveredNotification::class, 1);
        Notification::assertSentToTimes($this->lead, ShiftTakenLeadNotification::class, 1);
        Notification::assertSentToTimes($this->jordan, ShiftTakenNotification::class, 1);
        Notification::assertNothingSentTo($this->sam);
    }

    public function test_taking_back_your_own_released_shift(): void
    {
        $shift = $this->released();
        Notification::fake();

        app(TakeShift::class)->handle($this->robin, $shift, TakeHow::JustThisDate);

        $this->assertSame([$this->robin->id], $shift->assignments()->pluck('user_id')->all());
        $this->assertSame($this->robin->id, CoverRequest::query()->sole()->filled_by);
        Notification::assertSentToTimes($this->robin, ShiftTakenNotification::class, 1);
        Notification::assertNotSentTo($this->robin, ShiftCoveredNotification::class);
        Notification::assertSentTo($this->robin, ShiftTakenNotification::class, function (ShiftTakenNotification $notification) {
            $text = $this->text($notification->toMail($this->robin));

            return str_contains($text, 'Hi Robin, you\'re back on.') && ! str_contains($text, 'Note from');
        });
        Notification::assertSentTo($this->lead, ShiftTakenLeadNotification::class, fn (ShiftTakenLeadNotification $notification) => str_contains($this->text($notification->toMail($this->lead)), 'Robin E. took back their Front desk shift on Thursday, October 22, 6:30 to 9:30pm.'));
    }

    public function test_several_open_requests_are_filled_oldest_first(): void
    {
        $shift = $this->shift('2026-10-22', peopleNeeded: 2);
        $this->on($shift, $this->robin);
        $this->on($shift, $this->sam);
        app(ReleaseShifts::class)->one($this->robin, $shift, null);
        $this->travel(1)->minutes();
        app(ReleaseShifts::class)->one($this->sam, $shift, null);
        [$robins, $sams] = CoverRequest::query()->orderBy('id')->get()->all();
        Notification::fake();

        app(TakeShift::class)->handle($this->jordan, $shift, TakeHow::JustThisDate);

        $this->assertSame($this->jordan->id, $robins->fresh()->filled_by);
        $this->assertNull($sams->fresh()->filled_at);
        Notification::assertSentTo($this->robin, ShiftCoveredNotification::class);
        Notification::assertNotSentTo($this->sam, ShiftCoveredNotification::class);

        $casey = User::factory()->cleared()->create(['name' => 'Casey Example']);
        app(TakeShift::class)->handle($casey, $shift, TakeHow::JustThisDate);

        $this->assertSame($casey->id, $sams->fresh()->filled_by);
        Notification::assertSentTo($this->sam, ShiftCoveredNotification::class, fn (ShiftCoveredNotification $notification) => $notification->toMail($this->sam)->subject === 'Casey E. is covering your Thursday, October 22 shift');
    }

    public function test_taking_an_open_place_with_no_cover_request(): void
    {
        $shift = $this->shift('2026-10-22');
        Notification::fake();

        app(TakeShift::class)->handle($this->jordan, $shift, TakeHow::JustThisDate);

        $this->assertSame([$this->jordan->id], $shift->assignments()->pluck('user_id')->all());
        $this->assertSame(0, ChangeLogEntry::query()->ofAction('cover_request.filled')->count());
        Notification::assertSentTo($this->jordan, ShiftTakenNotification::class, fn (ShiftTakenNotification $notification) => $notification->toMail($this->jordan)->subject === 'You\'re on Front desk, Thursday, October 22'
            && ! str_contains($this->text($notification->toMail($this->jordan)), 'Note from'));
        Notification::assertSentToTimes($this->lead, ShiftTakenLeadNotification::class, 1);
        Notification::assertSentTo($this->lead, ShiftTakenLeadNotification::class, function (ShiftTakenLeadNotification $notification) {
            $mail = $notification->toMail($this->lead);

            return $mail->subject === 'Jordan E. took Front desk on Thursday, October 22'
                && str_contains($this->text($mail), 'Jordan E. took the open Front desk shift on Thursday, October 22, 6:30 to 9:30pm.');
        });
        Notification::assertNothingSentTo($this->coordinator);
    }

    public function test_taking_an_open_place_with_no_request_sends_shadows_nothing(): void
    {
        $casey = User::factory()->cleared()->create(['name' => 'Casey Example']);
        $shift = $this->shift('2026-10-22');
        $this->on($shift, $casey, AssignmentType::Shadow);
        Notification::fake();

        app(TakeShift::class)->handle($this->jordan, $shift, TakeHow::JustThisDate);

        Notification::assertNothingSentTo($casey);
        Notification::assertSentTo($this->jordan, ShiftTakenNotification::class, fn (ShiftTakenNotification $notification) => str_contains($this->text($notification->toMail($this->jordan)), 'Casey E. is shadowing.'));
    }

    public function test_a_lead_taking_a_shift_gets_only_the_taker_email(): void
    {
        $shift = $this->released();
        Notification::fake();

        app(TakeShift::class)->handle($this->lead, $shift, TakeHow::JustThisDate);

        Notification::assertSentToTimes($this->lead, ShiftTakenNotification::class, 1);
        Notification::assertNotSentTo($this->lead, ShiftTakenLeadNotification::class);
    }

    public function test_taking_every_week_makes_you_the_regular(): void
    {
        $slot = $this->thursdaySlot();
        $shift = $slot->shifts()->where('local_date', '2026-10-22')->sole();
        // Jordan is on Hosted events at the same time on October 29, so that date is left out.
        $busy = $this->shift('2026-10-29', $this->hostedEvents, from: '18:00', to: '20:00');
        $this->on($busy, $this->jordan);
        // Sam took November 12 on its own, so it already has the people it needs and is left out too.
        $this->on($slot->shifts()->where('local_date', '2026-11-12')->sole(), $this->sam);
        Notification::fake();

        $assignment = app(TakeShift::class)->handle($this->jordan, $shift, TakeHow::EveryWeek);

        $this->assertSame($shift->id, $assignment->shift_id);
        $regular = SlotRegular::query()->where('user_id', $this->jordan->id)->sole();
        $this->assertSame('2026-10-22', $regular->starts_on->toDateString());
        $this->assertNull($regular->ends_on);
        $this->assertSame(SlotFrequency::Weekly, $regular->frequency);
        $this->assertSame(['2026-10-22', '2026-10-29', '2026-11-05', '2026-11-19', '2026-11-26', '2026-12-03'], $this->datesFor($this->jordan));
        $this->assertFalse($slot->shifts()->where('local_date', '2026-10-29')->sole()->assignments()->exists());
        $this->assertSame([$this->sam->id], $slot->shifts()->where('local_date', '2026-11-12')->sole()->assignments()->pluck('user_id')->all());

        $entry = ChangeLogEntry::query()->about($this->jordan)->ofAction('regular.added')->sole();
        $this->assertSame($this->jordan->id, $entry->actor_id);
        $this->assertSame('Became the regular for Front desk, Thursdays, 6:30 to 9:30pm', Describe::entry($entry, $this->coordinator));
        $this->assertTrue(ChangeLogEntry::query()->ofAction('assignment.added')->about($this->jordan)->get()->every(fn (ChangeLogEntry $added) => $added->details['via'] === 'take' && $added->actor_id === $this->jordan->id));

        Notification::assertSentToTimes($this->jordan, ShiftTakenNotification::class, 1);
        Notification::assertNotSentTo($this->jordan, MadeRegularNotification::class);
        Notification::assertSentTo($this->jordan, ShiftTakenNotification::class, function (ShiftTakenNotification $notification) {
            $mail = $notification->toMail($this->jordan);
            $text = $this->text($mail);

            return $mail->subject === 'You\'re on Front desk, Thursday, October 22'
                && str_contains($text, 'You\'re now the Thursday regular for Front desk, starting Thursday, October 22.')
                && str_contains($text, 'Thursday, October 22, 6:30 to 9:30pm, Front desk.')
                && str_contains($text, 'You\'re not on Thursday, October 29 because you\'re already on another shift then.')
                && str_contains($text, 'You\'re not on Thursday, November 12 because they already have the people they need.');
        });
        Notification::assertSentToTimes($this->lead, ShiftTakenLeadNotification::class, 1);
        Notification::assertSentTo($this->lead, ShiftTakenLeadNotification::class, function (ShiftTakenLeadNotification $notification) {
            $mail = $notification->toMail($this->lead);

            return $mail->subject === 'Jordan E. took Front desk on Thursday, October 22'
                && str_contains($this->text($mail), 'Jordan E. is now the Thursday regular from Thursday, October 22.');
        });
    }

    public function test_taking_every_other_week_anchors_on_that_date(): void
    {
        $slot = $this->thursdaySlot();
        $shift = $slot->shifts()->where('local_date', '2026-10-22')->sole();
        Notification::fake();

        app(TakeShift::class)->handle($this->jordan, $shift, TakeHow::EveryOtherWeek);

        $regular = SlotRegular::query()->where('user_id', $this->jordan->id)->sole();
        $this->assertSame(SlotFrequency::EveryOtherWeek, $regular->frequency);
        $this->assertSame('2026-10-22', $regular->anchor_date->toDateString());
        $this->assertSame(['2026-10-22', '2026-11-05', '2026-11-19', '2026-12-03'], $this->datesFor($this->jordan));
        Notification::assertSentTo($this->jordan, ShiftTakenNotification::class, fn (ShiftTakenNotification $notification) => str_contains($this->text($notification->toMail($this->jordan)), 'You\'re now the every other Thursday regular for Front desk, starting Thursday, October 22.'));
        Notification::assertSentTo($this->lead, ShiftTakenLeadNotification::class, fn (ShiftTakenLeadNotification $notification) => str_contains($this->text($notification->toMail($this->lead)), 'Jordan E. is now the every other Thursday regular from Thursday, October 22.'));
    }

    public function test_taking_every_week_on_a_given_up_slot_fills_every_dates_request(): void
    {
        $casey = User::factory()->cleared()->create(['name' => 'Casey Example']);
        $slot = $this->thursdaySlot();
        $regular = app(AddRegular::class)->handle(null, $slot, $this->sam, '2026-10-15');
        // Casey shadows October 29 and November 5, so both go on hold when Sam gives up the slot.
        foreach (['2026-10-29', '2026-11-05'] as $date) {
            $this->on($slot->shifts()->where('local_date', $date)->sole(), $casey, AssignmentType::Shadow);
        }
        app(ReleaseShifts::class)->slot($this->sam, $regular, '2026-10-29', null);
        $shift = $slot->shifts()->where('local_date', '2026-10-29')->sole();
        $this->assertSame([TakeHow::JustThisDate, TakeHow::EveryWeek, TakeHow::EveryOtherWeek], app(TakeShift::class)->choices($shift));
        $this->assertSame(6, CoverRequest::query()->open()->count());
        Notification::fake();

        app(TakeShift::class)->handle($this->jordan, $shift, TakeHow::EveryWeek);

        $requests = CoverRequest::query()->orderBy('id')->get();
        $this->assertCount(6, $requests);
        $this->assertTrue($requests->every(fn (CoverRequest $request) => $request->filled_by === $this->jordan->id && $request->filled_at !== null));
        $filled = ChangeLogEntry::query()->ofAction('cover_request.filled')->get();
        $this->assertCount(6, $filled);
        $this->assertTrue($filled->every(fn (ChangeLogEntry $entry) => $entry->actor_id === $this->jordan->id && $entry->affected_user_id === $this->jordan->id && array_keys($entry->changes) === ['filled_by', 'filled_at']));

        Notification::assertSentToTimes($this->sam, ShiftCoveredNotification::class, 1);
        Notification::assertSentTo($this->sam, ShiftCoveredNotification::class, function (ShiftCoveredNotification $notification) {
            $mail = $notification->toMail($this->sam);
            $text = $this->text($mail);

            return $mail->subject === 'Jordan E. is covering 6 of your shifts'
                && str_contains($text, 'Hi Sam,')
                && str_contains($text, 'Jordan E. took your Front desk shifts on these dates. You don\'t need to do anything.')
                && str_contains($text, 'Thursday, October 29')
                && str_contains($text, 'Thursday, December 3');
        });
        Notification::assertSentToTimes($casey, ShadowSpotNotification::class, 1);
        Notification::assertSentTo($casey, ShadowSpotNotification::class, function (ShadowSpotNotification $notification) use ($casey) {
            $mail = $notification->toMail($casey);
            $text = $this->text($mail);

            return $notification->notice === ShadowSpotNotice::BackOn
                && $mail->subject === 'Your shadow shifts on 2 dates are back on'
                && str_contains($text, 'Jordan E. is covering, so you\'ll shadow Jordan E. on these shifts:')
                && str_contains($text, 'Front desk, Thursday, October 29, 6:30 to 9:30pm')
                && str_contains($text, 'Front desk, Thursday, November 5, 6:30 to 9:30pm');
        });
        Notification::assertSentToTimes($this->jordan, ShiftTakenNotification::class, 1);
        Notification::assertSentToTimes($this->lead, ShiftTakenLeadNotification::class, 1);
        Notification::assertSentTo($this->lead, ShiftTakenLeadNotification::class, function (ShiftTakenLeadNotification $notification) {
            $mail = $notification->toMail($this->lead);
            $text = $this->text($mail);

            return $mail->subject === 'Covered: Front desk on Thursday, October 29'
                && str_contains($text, 'Jordan E. is covering Sam E.\'s Front desk shift on Thursday, October 29, 6:30 to 9:30pm.')
                && str_contains($text, 'Jordan E. is now the Thursday regular from Thursday, October 29.');
        });
    }

    public function test_weekly_choices_only_on_a_slot_with_no_regular(): void
    {
        $take = app(TakeShift::class);

        $open = $this->thursdaySlot();
        $this->assertSame([TakeHow::JustThisDate, TakeHow::EveryWeek, TakeHow::EveryOtherWeek], $take->choices($open->shifts()->where('local_date', '2026-10-22')->sole()));

        $withRegular = $this->thursdaySlot(['weekday' => 5]);
        app(AddRegular::class)->handle(null, $withRegular, $this->robin, '2026-10-16');
        $friday = $withRegular->shifts()->where('local_date', '2026-10-23')->sole();
        app(ReleaseShifts::class)->one($this->robin, $friday, null);
        $this->assertSame([TakeHow::JustThisDate], $take->choices($friday->fresh()));
        $this->assertRefused('You can take this date only.', $this->jordan, $friday->fresh(), TakeHow::EveryWeek);

        $rotation = $this->thursdaySlot(['weekday' => 6, 'is_rotation' => true]);
        $saturday = $rotation->shifts()->where('local_date', '2026-10-24')->sole();
        $this->assertSame([TakeHow::JustThisDate], $take->choices($saturday));
        $this->assertRefused('You can take this date only.', $this->jordan, $saturday, TakeHow::EveryOtherWeek);

        $oneTime = $this->shift('2026-10-20');
        $this->assertSame([TakeHow::JustThisDate], $take->choices($oneTime));
        $this->assertRefused('You can take this date only.', $this->jordan, $oneTime, TakeHow::EveryWeek);

        $everyOther = $this->thursdaySlot(['weekday' => 1, 'frequency' => SlotFrequency::EveryOtherWeek, 'anchor_date' => '2026-10-19']);
        $this->assertSame([TakeHow::JustThisDate, TakeHow::EveryWeek], $take->choices($everyOther->shifts()->where('local_date', '2026-10-19')->sole()));

        $this->assertSame(0, SlotRegular::query()->where('user_id', $this->jordan->id)->count());
        $this->assertSame([], $this->datesFor($this->jordan));
    }

    public function test_it_refuses_a_cancelled_shift(): void
    {
        $shift = $this->released();
        app(CancelShift::class)->handle($this->coordinator, $shift, 'Power outage');

        $this->assertRefused('This shift was cancelled.', $this->jordan, $shift);
    }

    public function test_it_refuses_a_shift_that_has_started(): void
    {
        $shift = $this->released();
        $this->travelTo(CarbonImmutable::parse('2026-10-22 18:45', self::TIMEZONE));

        $this->assertRefused('This shift has already started.', $this->jordan, $shift);
    }

    public function test_it_refuses_someone_already_on_it(): void
    {
        $shift = $this->shift('2026-10-22', peopleNeeded: 2);
        $this->on($shift, $this->jordan);
        $this->on($shift, $this->sam, AssignmentType::Shadow);

        $this->assertRefused('You\'re already on this shift.', $this->jordan, $shift);
        $this->assertRefused('You\'re shadowing this shift.', $this->sam, $shift);
    }

    public function test_taking_it_again_in_another_tab_says_youre_already_on_it(): void
    {
        $shift = $this->released();
        $stale = Shift::query()->findOrFail($shift->id);
        Notification::fake();

        app(TakeShift::class)->handle($this->jordan, $shift, TakeHow::JustThisDate);

        $this->assertRefused('You\'re already on this shift.', $this->jordan, $stale);
    }

    public function test_it_refuses_someone_on_an_overlapping_shift(): void
    {
        $shift = $this->released();
        $this->on($this->shift('2026-10-22', $this->hostedEvents, from: '18:00', to: '20:00'), $this->jordan);

        $this->assertRefused('You\'re already on Hosted events, Thursday, October 22, 6 to 8pm.', $this->jordan, $shift);
    }

    public function test_it_refuses_a_shift_with_no_open_place(): void
    {
        $shift = $this->shift('2026-10-22');
        $this->on($shift, $this->robin);

        $this->expectException(ShiftAlreadyTaken::class);

        app(TakeShift::class)->handle($this->jordan, $shift, TakeHow::JustThisDate);
    }

    public function test_a_turned_off_person_is_refused_under_the_lock(): void
    {
        $shift = $this->released();
        $stale = User::query()->findOrFail($this->jordan->id);
        User::query()->whereKey($this->jordan->id)->update(['deactivated_at' => now()]);

        $this->assertRefused('Your account has been turned off, so you can\'t take shifts.', $stale, $shift);

        $slot = $this->thursdaySlot();
        $this->assertRefused('Your account has been turned off, so you can\'t take shifts.', $stale, $slot->shifts()->where('local_date', '2026-10-22')->sole(), TakeHow::EveryWeek);

        $this->assertSame([], $this->datesFor($this->jordan));
        $this->assertNull(CoverRequest::query()->sole()->filled_at);
    }

    public function test_every_week_refuses_a_date_it_cant_take(): void
    {
        $slot = $this->thursdaySlot();
        $shift = $slot->shifts()->where('local_date', '2026-10-22')->sole();
        $this->on($shift, $this->robin);

        try {
            app(TakeShift::class)->handle($this->jordan, $shift, TakeHow::EveryWeek);
            $this->fail('A full date should be refused.');
        } catch (ShiftAlreadyTaken) {
        }

        $this->on($this->shift('2026-10-22', $this->hostedEvents, from: '18:00', to: '20:00'), $this->sam);
        $this->assertRefused('You\'re already on Hosted events, Thursday, October 22, 6 to 8pm.', $this->sam, $shift, TakeHow::EveryWeek);

        $this->assertSame(0, SlotRegular::query()->count());
    }

    public function test_a_coordinator_filling_a_released_shift_fills_its_request(): void
    {
        $casey = User::factory()->cleared()->create(['name' => 'Casey Example']);
        $shift = $this->shift('2026-10-22');
        $this->on($shift, $this->robin);
        $this->on($shift, $casey, AssignmentType::Shadow);
        app(ReleaseShifts::class)->one($this->robin, $shift, null);
        Notification::fake();

        app(PutOnShift::class)->handle($this->coordinator, $shift->fresh(), $this->sam, AssignmentType::Regular);

        $request = CoverRequest::query()->sole();
        $this->assertSame($this->sam->id, $request->filled_by);
        $filled = ChangeLogEntry::query()->ofAction('cover_request.filled')->sole();
        $this->assertSame($this->coordinator->id, $filled->actor_id);
        $this->assertSame($this->sam->id, $filled->affected_user_id);
        $this->assertSame(['filled_by', 'filled_at'], array_keys($filled->changes));

        Notification::assertSentToTimes($this->sam, PutOnShiftNotification::class, 1);
        Notification::assertNotSentTo($this->sam, ShiftTakenNotification::class);
        Notification::assertSentTo($this->robin, ShiftCoveredNotification::class, fn (ShiftCoveredNotification $notification) => $notification->toMail($this->robin)->subject === 'Sam E. is covering your Thursday, October 22 shift');
        Notification::assertSentTo($casey, ShadowSpotNotification::class, fn (ShadowSpotNotification $notification) => $notification->notice === ShadowSpotNotice::BackOn);
        Notification::assertNothingSentTo($this->lead);
        Notification::assertNothingSentTo($this->coordinator);
    }

    public function test_putting_a_shadow_on_a_released_shift_doesnt_fill_it(): void
    {
        $shift = $this->released();
        Notification::fake();

        app(PutOnShift::class)->handle($this->coordinator, $shift, $this->sam, AssignmentType::Shadow);

        $this->assertNull(CoverRequest::query()->sole()->filled_at);
        Notification::assertNothingSentTo($this->robin);
    }

    public function test_the_policy_lets_anyone_who_can_sign_in_take_a_shift(): void
    {
        $shift = $this->shift('2026-10-22');

        $this->assertTrue(Gate::forUser($this->jordan)->allows('take', $shift));
        $this->assertFalse(Gate::forUser(User::factory()->deactivated()->create())->allows('take', $shift));
        $this->assertFalse(Gate::forUser(User::factory()->notInvited()->create())->allows('take', $shift));
    }
}
