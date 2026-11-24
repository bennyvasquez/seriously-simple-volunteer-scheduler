<?php

namespace Tests\Feature\Scheduling;

use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\MoveBackToShadowing;
use App\Actions\Scheduling\AddRegular;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\GenerateShifts;
use App\ChangeLog\Describe;
use App\Cover\CoverLadder;
use App\Cover\ShadowProgress;
use App\Enums\AssignmentType;
use App\Enums\Role;
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
use App\Notifications\MovedBackNotification;
use App\Notifications\ShiftsReleasedNotification;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class MoveBackTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private Area $frontDesk;

    private Area $hostedEvents;

    private User $coordinator;

    private User $morgan;

    private User $robin;

    private User $sam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutDefer();
        // Wednesday, October 14, 2026, 9am in Detroit.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));
        Organization::factory()->create(['name' => 'Example Makerspace', 'timezone' => self::TIMEZONE, 'weeks_ahead' => 2]);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk', 'lead_name' => 'Shift lead']);
        $this->hostedEvents = Area::factory()->create(['name' => 'Hosted events']);
        $this->coordinator = $this->withRole('Chris Coordinator', Role::Coordinator);
        $this->morgan = User::factory()->cleared()->create(['name' => 'Morgan Example']);
        $this->robin = User::factory()->cleared()->create(['name' => 'Robin Example']);
        $this->sam = User::factory()->cleared()->create(['name' => 'Sam Example']);
    }

    private function withRole(string $name, Role $role, ?Area $area = null): User
    {
        $user = User::factory()->create(['name' => $name]);
        app(GrantRole::class)->handle(null, $user, $role, $area);
        $this->enableTwoFactor($user);

        return $user;
    }

    /** Morgan becomes the regular on a Thursday Front desk slot from Thursday, October 15, with its shifts generated `$weeksAhead` weeks out. */
    private function morgansThursdays(int $weeksAhead = 2): SlotRegular
    {
        Organization::current()->update(['weeks_ahead' => $weeksAhead]);
        $slot = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'weekday' => 4, 'start_time' => '18:30', 'end_time' => '21:30', 'people_needed' => 1, 'shadow_spots' => 1]);
        app(GenerateShifts::class)->handle(Organization::current());

        return app(AddRegular::class)->handle(null, $slot, $this->morgan, '2026-10-15');
    }

    private function morgansSaturday(): Shift
    {
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->hostedEvents, '2026-10-17', '10:00', '14:00', 1, 0, null);
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $this->morgan->id, 'type' => AssignmentType::Regular]);

        return $shift;
    }

    /** @return list<string> */
    private function datesFor(User $person, ?AssignmentType $type = null): array
    {
        return Shift::query()
            ->whereHas('assignments', fn ($query) => $query->where('user_id', $person->id)->when($type !== null, fn ($query) => $query->where('type', $type)))
            ->orderBy('starts_at')->get()
            ->map(fn (Shift $shift) => $shift->local_date->toDateString())
            ->all();
    }

    private function text(MailMessage $mail): string
    {
        return implode("\n", array_merge([$mail->greeting], $mail->introLines, $mail->outroLines));
    }

    public function test_keep_leaves_their_shifts_and_regulars(): void
    {
        $regular = $this->morgansThursdays();
        $this->morgansSaturday();
        $before = $this->datesFor($this->morgan);
        Notification::fake();

        $released = app(MoveBackToShadowing::class)->handle($this->coordinator, $this->morgan, false, "  Needs more practice.\n");

        $this->assertCount(0, $released);
        $this->assertFalse($this->morgan->isCleared());
        $this->assertFalse($this->morgan->fresh()->isCleared());
        $this->assertSame(['2026-10-15', '2026-10-17', '2026-10-22'], $before);
        $this->assertSame($before, $this->datesFor($this->morgan));
        $this->assertNull($regular->fresh()->ends_on);
        $this->assertSame(0, CoverRequest::query()->count());

        $entry = ChangeLogEntry::query()->ofAction('user.moved_back')->about($this->morgan)->sole();
        $this->assertSame($this->coordinator->id, $entry->actor_id);
        $this->assertSame('Chris Coordinator', $entry->actor_name);
        $this->assertSame(['release' => false], $entry->details);
        $this->assertNotNull($entry->changes['cleared_at']['old']);
        $this->assertNull($entry->changes['cleared_at']['new']);
        $this->assertSame(['old' => null, 'new' => now()->toDateTimeString()], $entry->changes['shadowing_since']);
        $this->assertTrue($this->morgan->fresh()->shadowing_since->equalTo(now()));
        $this->assertSame(['old' => null, 'new' => 'Needs more practice.'], $entry->changes['note']);
        $this->assertSame('Moved Morgan Example back to shadowing', Describe::entry($entry, $this->coordinator));
        $this->assertSame('Note: Needs more practice.', Describe::changes($entry));
        $this->assertSame(0, ChangeLogEntry::query()->whereIn('action', ['regular.ended', 'assignment.removed', 'cover_request.created'])->count());

        Notification::assertSentToTimes($this->morgan, MovedBackNotification::class, 1);
        Notification::assertNotSentTo($this->morgan, ShiftsReleasedNotification::class);
        Notification::assertNotSentTo($this->robin, CoverRequestNotification::class);
    }

    public function test_release_ends_their_regulars_and_asks_for_cover(): void
    {
        $regular = $this->morgansThursdays(4);
        // Tuesday, October 20: Thursday, October 15 has been worked.
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00', self::TIMEZONE));
        $future = $regular->weeklySlot->shifts()->where('local_date', '>', '2026-10-20')->orderBy('starts_at')->pluck('id')->all();
        $this->assertCount(3, $future);
        Notification::fake();

        $released = app(MoveBackToShadowing::class)->handle($this->coordinator, $this->morgan, true, null);

        $this->assertSame($future, $released->pluck('id')->all());
        $this->assertSame(['2026-10-15'], $this->datesFor($this->morgan));
        $this->assertSame('2026-10-19', $regular->fresh()->ends_on->toDateString());

        $requests = CoverRequest::query()->orderBy('shift_id')->get();
        $this->assertSame($future, $requests->pluck('shift_id')->all());
        $this->assertTrue($requests->every(fn (CoverRequest $request) => $request->released_by === $this->morgan->id && $request->note === null && $request->notified_count === 2 && $request->escalated_at === null));

        $moved = ChangeLogEntry::query()->ofAction('user.moved_back')->about($this->morgan)->sole();
        $this->assertSame(['release' => true], $moved->details);

        $ended = ChangeLogEntry::query()->ofAction('regular.ended')->about($this->morgan)->sole();
        $this->assertSame($this->coordinator->id, $ended->actor_id);
        $this->assertSame('user.moved_back', $ended->details['via']);
        $this->assertSame(['ends_on' => ['old' => null, 'new' => '2026-10-19']], $ended->changes);

        $removed = ChangeLogEntry::query()->ofAction('assignment.removed')->orderBy('subject_id')->get();
        $this->assertSame($future, $removed->pluck('subject_id')->all());
        $this->assertTrue($removed->every(fn (ChangeLogEntry $entry) => $entry->details['via'] === 'release:move_back' && $entry->actor_id === $this->coordinator->id && $entry->affected_user_id === $this->morgan->id));
        $this->assertSame('Released Morgan Example\'s place on the Front desk shift on Thursday, October 22, 6:30 to 9:30pm', Describe::entry($removed->first(), $this->coordinator));

        $created = ChangeLogEntry::query()->ofAction('cover_request.created')->orderBy('subject_id')->get();
        $this->assertSame($future, $created->pluck('subject_id')->all());
        $this->assertTrue($created->every(fn (ChangeLogEntry $entry) => $entry->actor_id === $this->coordinator->id && $entry->affected_user_id === $this->morgan->id));

        // The coordinator who moved Morgan back released these shifts themselves, so they aren't asked to cover them.
        Notification::assertNotSentTo($this->coordinator, CoverRequestNotification::class);
        foreach ([$this->robin, $this->sam] as $person) {
            Notification::assertSentToTimes($person, CoverRequestNotification::class, 1);
            Notification::assertSentTo($person, CoverRequestNotification::class, function (CoverRequestNotification $notification) use ($person) {
                $text = $this->text($notification->toMail($person));

                return count($notification->shifts) === 3 && str_contains($text, 'Morgan E. can\'t make their Front desk shifts.');
            });
        }
        Notification::assertNotSentTo($this->morgan, CoverRequestNotification::class);

        // The nightly generator, run further out, doesn't put Morgan back on.
        Organization::current()->update(['weeks_ahead' => 8]);
        $this->artisan('ssvs:generate-shifts')->assertSuccessful();
        $this->assertGreaterThan(5, $regular->weeklySlot->shifts()->count());
        $this->assertSame(['2026-10-15'], $this->datesFor($this->morgan));
    }

    public function test_release_leaves_started_shifts_and_shadow_places(): void
    {
        $regular = $this->morgansThursdays(4);
        $slot = $regular->weeklySlot;
        $cancelled = $slot->shifts()->where('local_date', '2026-10-29')->sole();
        $cancelled->update(['cancelled_at' => now(), 'cancel_reason' => 'Power outage']);
        $saturday = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->hostedEvents, '2026-10-24', '10:00', '14:00', 1, 1, null);
        Assignment::create(['shift_id' => $saturday->id, 'user_id' => $this->robin->id, 'type' => AssignmentType::Regular]);
        Assignment::create(['shift_id' => $saturday->id, 'user_id' => $this->morgan->id, 'type' => AssignmentType::Shadow]);
        // Thursday, October 22, 7pm: that day's shift started at 6:30.
        $this->travelTo(CarbonImmutable::parse('2026-10-22 19:00', self::TIMEZONE));
        Notification::fake();

        $released = app(MoveBackToShadowing::class)->handle($this->coordinator, $this->morgan, true, null);

        $this->assertSame(['2026-11-05'], $released->map(fn (Shift $shift) => $shift->local_date->toDateString())->all());
        $this->assertSame(['2026-10-15', '2026-10-22'], $this->datesFor($this->morgan, AssignmentType::Regular));
        $this->assertSame(['2026-10-24'], $this->datesFor($this->morgan, AssignmentType::Shadow));
        $this->assertSame(['2026-11-05'], CoverRequest::query()->with('shift')->get()->map(fn (CoverRequest $request) => $request->shift->local_date->toDateString())->all());

        // The cancelled shift loses Morgan with nobody asked.
        $quiet = ChangeLogEntry::query()->ofAction('assignment.removed')->forSubject($cancelled)->sole();
        $this->assertSame('user.moved_back', $quiet->details['via']);
        $this->assertSame($this->morgan->id, $quiet->affected_user_id);
        $this->assertSame($this->coordinator->id, $quiet->actor_id);
        $this->assertSame(2, ChangeLogEntry::query()->ofAction('assignment.removed')->count());
    }

    public function test_a_shift_inside_24_hours_escalates_once(): void
    {
        $lead = $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $otherCoordinator = $this->withRole('Jordan Coordinator', Role::Coordinator);
        $regular = $this->morgansThursdays();
        $tomorrow = $regular->weeklySlot->shifts()->where('local_date', '2026-10-15')->sole();
        Notification::fake();
        // Wednesday, 7pm: Thursday's shift starts in 23 and a half hours.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 19:00', self::TIMEZONE));

        $released = app(MoveBackToShadowing::class)->handle($this->coordinator, $this->morgan, true, null);

        $this->assertCount(2, $released);
        $this->assertSame([$tomorrow->id], CoverRequest::query()->whereNotNull('escalated_at')->pluck('shift_id')->all());
        $escalated = ChangeLogEntry::query()->ofAction('cover_request.escalated')->sole();
        $this->assertSame($tomorrow->id, $escalated->subject_id);
        $this->assertSame($this->coordinator->id, $escalated->actor_id);

        foreach ([$lead, $otherCoordinator] as $person) {
            Notification::assertSentToTimes($person, CoverUrgentNotification::class, 1);
            Notification::assertSentTo($person, CoverUrgentNotification::class, fn (CoverUrgentNotification $notification) => count($notification->shifts) === 1);
            // The cover request lists only the shift that didn't escalate, so nobody hears about tomorrow twice.
            Notification::assertSentTo($person, CoverRequestNotification::class, fn (CoverRequestNotification $notification) => count($notification->shifts) === 1);
        }
        // The coordinator who moved Morgan back released tomorrow's shift themselves, so they get no urgent email about it (the 2026-11-21 rule).
        Notification::assertNotSentTo($this->coordinator, CoverUrgentNotification::class);
        Notification::assertNotSentTo($this->coordinator, CoverRequestNotification::class);
        Notification::assertSentTo($this->robin, CoverRequestNotification::class, fn (CoverRequestNotification $notification) => count($notification->shifts) === 2);
        Notification::assertNotSentTo($this->morgan, CoverUrgentNotification::class);

        $this->travelTo(CarbonImmutable::parse('2026-10-14 19:10', self::TIMEZONE));
        $this->assertSame(['resent' => 0, 'escalated' => 0], app(CoverLadder::class)->run());
        Notification::assertSentToTimes($lead, CoverUrgentNotification::class, 1);
    }

    /** A shadow shift for Morgan on Front desk that ended at `$endsAt`, made directly since the actions refuse dates in the past. */
    private function finishedShadowShift(string $startsAt, string $endsAt): void
    {
        $shift = Shift::create(['area_id' => $this->frontDesk->id, 'local_date' => substr($startsAt, 0, 10), 'starts_at' => CarbonImmutable::parse($startsAt, self::TIMEZONE)->utc(), 'ends_at' => CarbonImmutable::parse($endsAt, self::TIMEZONE)->utc(), 'people_needed' => 1, 'shadow_spots' => 1]);
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $this->morgan->id, 'type' => AssignmentType::Shadow]);
    }

    public function test_shadow_progress_starts_over_after_a_move_back(): void
    {
        $this->finishedShadowShift('2026-10-03 18:30', '2026-10-03 21:30');
        $this->finishedShadowShift('2026-10-10 18:30', '2026-10-10 21:30');
        $this->assertSame(2, ShadowProgress::done($this->morgan));

        app(MoveBackToShadowing::class)->handle($this->coordinator, $this->morgan, false, null);

        $this->assertSame(0, ShadowProgress::done($this->morgan));
        $this->assertNotContains($this->morgan->id, ShadowProgress::readyForReview()->pluck('id')->all());
        $this->actingAs($this->morgan)->get(route('home'))->assertOk()->assertSeeText('Shadow shifts done: 0 of 2');
        // The person page still lists both earlier shadow shifts.
        $this->assertCount(2, ShadowProgress::history($this->morgan));

        // Thursday, October 15, 10pm: one more shadow shift has ended since the move back.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 22:00', self::TIMEZONE));
        $this->finishedShadowShift('2026-10-15 18:30', '2026-10-15 21:30');

        $this->assertSame(1, ShadowProgress::done($this->morgan));
        $this->assertNotContains($this->morgan->id, ShadowProgress::readyForReview()->pluck('id')->all());
        $this->actingAs($this->morgan)->get(route('home'))->assertOk()->assertSeeText('Shadow shifts done: 1 of 2');

        // A second one makes them Ready for review again.
        $this->finishedShadowShift('2026-10-15 17:00', '2026-10-15 18:00');
        $this->assertContains($this->morgan->id, ShadowProgress::readyForReview()->pluck('id')->all());
    }

    public function test_someone_with_a_role_cant_be_moved_back(): void
    {
        $this->morgansThursdays();
        app(GrantRole::class)->handle($this->coordinator, $this->morgan, Role::AreaLead, $this->frontDesk);

        try {
            app(MoveBackToShadowing::class)->handle($this->coordinator, $this->morgan, true, null);
            $this->fail('Someone with a role was moved back.');
        } catch (DomainException $e) {
            $this->assertSame('Morgan E. has a role, so they stay cleared. Remove their role first.', $e->getMessage());
        }

        $this->assertTrue($this->morgan->fresh()->isCleared());
        $this->assertSame(0, CoverRequest::query()->count());
        $this->assertSame(0, ChangeLogEntry::query()->ofAction('user.moved_back')->count());

        // The person page offers no button, and the page itself sends them back with the reason.
        $this->actingAs($this->coordinator)->get(route('people.show', $this->morgan))->assertOk()->assertDontSee('Move back to shadowing');
        $this->actingAs($this->coordinator)->get(route('people.move-back', $this->morgan))
            ->assertRedirect(route('people.show', $this->morgan))
            ->assertSessionHasErrors(['clear' => 'Morgan E. has a role, so they stay cleared. Remove their role first.']);
        $this->actingAs($this->coordinator)->from(route('people.move-back', $this->morgan))->post(route('people.move-back.store', $this->morgan), ['existing' => 'release'])
            ->assertSessionHasErrors(['move_back' => 'Morgan E. has a role, so they stay cleared. Remove their role first.']);
        $this->assertTrue($this->morgan->fresh()->isCleared());
    }

    public function test_someone_not_cleared_cant_be_moved_back(): void
    {
        $casey = User::factory()->create(['name' => 'Casey Example']);

        try {
            app(MoveBackToShadowing::class)->handle($this->coordinator, $casey, false, null);
            $this->fail('Someone not cleared was moved back.');
        } catch (DomainException $e) {
            $this->assertSame('Casey E. isn\'t cleared.', $e->getMessage());
        }

        $this->assertSame(0, ChangeLogEntry::query()->ofAction('user.moved_back')->count());
    }

    public function test_only_coordinators_and_admins_can_move_someone_back(): void
    {
        $this->morgansThursdays();
        $lead = $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);

        // The lead can see Morgan (a regular in their area) but can't move them back.
        $this->actingAs($lead)->get(route('people.show', $this->morgan))->assertOk()->assertDontSee('Move back to shadowing');
        $this->actingAs($lead)->get(route('people.move-back', $this->morgan))->assertForbidden();
        $this->actingAs($lead)->post(route('people.move-back.store', $this->morgan), ['existing' => 'keep'])->assertForbidden();
        // Sam has nothing in the lead's area, so the lead can't see them at all.
        $this->actingAs($lead)->get(route('people.move-back', $this->sam))->assertNotFound();
        $this->actingAs($this->robin)->get(route('people.move-back', $this->morgan))->assertForbidden();

        $this->assertTrue($this->morgan->fresh()->isCleared());
        $this->assertSame(0, ChangeLogEntry::query()->ofAction('user.moved_back')->count());
    }

    public function test_the_person_gets_one_email(): void
    {
        $this->morgansThursdays();
        $this->morgansSaturday();
        Notification::fake();

        app(MoveBackToShadowing::class)->handle($this->coordinator, $this->morgan, true, 'Not emailed.');

        Notification::assertSentToTimes($this->morgan, MovedBackNotification::class, 1);
        Notification::assertSentTo($this->morgan, MovedBackNotification::class, function (MovedBackNotification $notification) {
            $mail = $notification->toMail($this->morgan);
            $text = $this->text($mail);

            return $mail->subject === 'You\'ve been moved back to shadowing'
                && str_contains($text, 'Hi Morgan,')
                && str_contains($text, 'Chris C. moved you back to shadowing at Example Makerspace. You can sign up for shadow spots from Home, and a coordinator will review you again once you\'ve done 2 shadow shifts.')
                && str_contains($text, 'You\'re off these shifts, and other volunteers were asked to cover them:')
                && ! str_contains($text, 'nobody was asked')
                && str_contains($text, 'Front desk, Thursday, October 15, 6:30 to 9:30pm')
                && str_contains($text, 'Hosted events, Saturday, October 17, 10am to 2pm')
                && str_contains($text, 'Front desk, Thursday, October 22, 6:30 to 9:30pm')
                && str_contains($text, 'You\'re no longer the regular on Thursdays, Front desk.')
                && ! str_contains($text, 'Not emailed.');
        });
        Notification::assertNotSentTo($this->morgan, ShiftsReleasedNotification::class);
        Notification::assertNotSentTo($this->morgan, CoverRequestNotification::class);
    }

    public function test_keeping_their_shifts_leaves_the_released_lines_out_of_the_email(): void
    {
        $this->morgansThursdays();
        Notification::fake();

        app(MoveBackToShadowing::class)->handle($this->coordinator, $this->morgan, false, null);

        Notification::assertSentTo($this->morgan, MovedBackNotification::class, function (MovedBackNotification $notification) {
            $text = $this->text($notification->toMail($this->morgan));

            return str_contains($text, 'Chris C. moved you back to shadowing at Example Makerspace.')
                && ! str_contains($text, 'These shifts')
                && ! str_contains($text, 'no longer the regular');
        });
    }

    public function test_the_email_says_which_shifts_asked_for_cover_and_which_asked_nobody(): void
    {
        $this->morgansThursdays();
        $saturday = $this->morgansSaturday();
        // Robin is on Saturday too, and it needs only one person, so Morgan coming off it leaves it with the people it needs.
        Assignment::create(['shift_id' => $saturday->id, 'user_id' => $this->robin->id, 'type' => AssignmentType::Regular]);
        Notification::fake();

        app(MoveBackToShadowing::class)->handle($this->coordinator, $this->morgan, true, null);

        Notification::assertSentTo($this->morgan, MovedBackNotification::class, function (MovedBackNotification $notification) {
            $text = $this->text($notification->toMail($this->morgan));
            $asked = strpos($text, 'You\'re off these shifts, and other volunteers were asked to cover them:');
            $nobody = strpos($text, 'You\'re off these shifts too. They still have the people they need, so nobody was asked:');

            return $asked !== false && $nobody !== false
                && strpos($text, 'Front desk, Thursday, October 15, 6:30 to 9:30pm') > $asked
                && strpos($text, 'Front desk, Thursday, October 15, 6:30 to 9:30pm') < $nobody
                && strpos($text, 'Hosted events, Saturday, October 17, 10am to 2pm') > $nobody
                && ! str_contains($text, 'given to other volunteers');
        });
    }

    public function test_the_email_leaves_out_the_cover_line_when_every_shift_asked_nobody(): void
    {
        $saturday = $this->morgansSaturday();
        Assignment::create(['shift_id' => $saturday->id, 'user_id' => $this->robin->id, 'type' => AssignmentType::Regular]);
        Notification::fake();

        app(MoveBackToShadowing::class)->handle($this->coordinator, $this->morgan, true, null);

        Notification::assertSentTo($this->morgan, MovedBackNotification::class, function (MovedBackNotification $notification) {
            $text = $this->text($notification->toMail($this->morgan));

            return str_contains($text, 'You\'re off these shifts too. They still have the people they need, so nobody was asked:')
                && str_contains($text, 'Hosted events, Saturday, October 17, 10am to 2pm')
                && ! str_contains($text, 'other volunteers were asked');
        });
    }

    public function test_the_move_back_screen(): void
    {
        $this->morgansThursdays();
        $this->morgansSaturday();

        $this->actingAs($this->coordinator)->get(route('people.show', $this->morgan))
            ->assertOk()
            ->assertSee(route('people.move-back', $this->morgan))
            ->assertSeeText('Move back to shadowing');

        $this->actingAs($this->coordinator)->get(route('people.move-back', $this->morgan))
            ->assertOk()
            ->assertSeeText('Move Morgan E. back to shadowing?')
            ->assertSeeText('Morgan E. won\'t be able to take regular shifts until a coordinator clears them again. They can still sign up for shadow spots.')
            ->assertSeeText('Morgan E. has 3 upcoming regular shifts')
            ->assertSeeTextInOrder(['Keep them', 'Morgan E. stays on Thu Oct 15, Sat Oct 17 and Thu Oct 22.', 'Release them', 'They go out as cover requests right away, with the usual reminders.', 'Morgan E. stops being the regular on Thursdays, Front desk.'])
            ->assertSee('name="existing" value="keep"', false)
            ->assertSee('name="existing" value="release"', false)
            ->assertSeeText('Note for the change log (optional)')
            ->assertSee('name="note"', false)
            ->assertSeeText('Move back to shadowing')
            ->assertSeeText('Cancel');

        // A choice is needed while one is shown.
        $this->actingAs($this->coordinator)->from(route('people.move-back', $this->morgan))->post(route('people.move-back.store', $this->morgan), ['note' => 'Hmm.'])
            ->assertRedirect(route('people.move-back', $this->morgan))
            ->assertSessionHasErrors(['existing' => 'Choose keep or release.']);
        $this->assertTrue($this->morgan->fresh()->isCleared());

        Notification::fake();
        $this->actingAs($this->coordinator)->post(route('people.move-back.store', $this->morgan), ['existing' => 'release', 'note' => 'Needs more practice.'])
            ->assertRedirect(route('people.show', $this->morgan))
            ->assertSessionHas('status', 'Morgan E. is back to shadowing. Their 3 upcoming regular shifts were released.');
        $this->assertFalse($this->morgan->fresh()->isCleared());
        $this->assertSame([], $this->datesFor($this->morgan));
        $this->assertSame('Needs more practice.', ChangeLogEntry::query()->ofAction('user.moved_back')->sole()->changes['note']['new']);
    }

    public function test_the_move_back_screen_with_nothing_upcoming(): void
    {
        $page = $this->actingAs($this->coordinator)->get(route('people.move-back', $this->sam))
            ->assertOk()
            ->assertSeeText('Move Sam E. back to shadowing?')
            ->assertDontSeeText('Keep them')
            ->assertDontSeeText('Release them');
        $page->assertDontSee('name="existing"', false);

        $this->actingAs($this->coordinator)->post(route('people.move-back.store', $this->sam))
            ->assertRedirect(route('people.show', $this->sam))
            ->assertSessionHas('status', 'Sam E. is back to shadowing.');
        $this->assertFalse($this->sam->fresh()->isCleared());
        $this->assertSame(['release' => false], ChangeLogEntry::query()->ofAction('user.moved_back')->sole()->details);
    }

    public function test_the_move_back_screen_for_a_regular_with_no_shifts_on_the_calendar(): void
    {
        $slot = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'weekday' => 4, 'start_time' => '18:30', 'end_time' => '21:30', 'people_needed' => 1]);
        SlotRegular::create(['weekly_slot_id' => $slot->id, 'user_id' => $this->morgan->id, 'starts_on' => '2026-12-03', 'frequency' => 'weekly', 'anchor_date' => '2026-12-03']);

        $this->actingAs($this->coordinator)->get(route('people.move-back', $this->morgan))
            ->assertOk()
            ->assertSeeText('Morgan E. is a regular, but none of their shifts are on the calendar yet')
            ->assertSeeTextInOrder(['Keep them', 'Morgan E. stays the regular on Thursdays, Front desk.', 'Release them', 'Morgan E. stops being the regular on Thursdays, Front desk.'])
            ->assertDontSeeText('They go out as cover requests');
    }
}
