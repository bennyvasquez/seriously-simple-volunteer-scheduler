<?php

namespace Tests\Feature\Scheduling;

use App\Actions\Accounts\DeactivateUser;
use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\AddRegular;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\EndRegular;
use App\Actions\Scheduling\GenerateShifts;
use App\Actions\Scheduling\ReleaseShifts;
use App\Actions\Scheduling\RemoveFromShift;
use App\Actions\Scheduling\SendChangeNotices;
use App\Actions\Scheduling\UpdateShift;
use App\Actions\Scheduling\UpdateWeeklySlot;
use App\ChangeLog\Describe;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\ChangeNotice;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Models\WeeklySlot;
use App\Notifications\ShiftChangedNotification;
use App\Notifications\ShiftTimeChangedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class ChangeNoticesTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private Area $frontDesk;

    private User $coordinator;

    private User $sam;

    private User $jordan;

    private WeeklySlot $slot;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->withoutDefer();
        // Wednesday, October 14, 2026, 9am in Detroit.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));
        Organization::factory()->create(['timezone' => self::TIMEZONE, 'weeks_ahead' => 8]);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk']);
        $this->coordinator = User::factory()->create(['name' => 'Coordinator Example']);
        app(GrantRole::class)->handle(null, $this->coordinator, Role::Coordinator, null);
        $this->enableTwoFactor($this->coordinator);
        $this->sam = User::factory()->create(['name' => 'Sam Example', 'email' => 'sam@example.org']);
        $this->jordan = User::factory()->create(['name' => 'Jordan Example', 'email' => 'jordan@example.org']);
        // Thursdays, 6:30 to 9:30pm.
        $this->slot = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'weekday' => 4, 'start_time' => '18:30', 'end_time' => '21:30', 'people_needed' => 2]);
        app(GenerateShifts::class)->handle(Organization::current());
    }

    /** Sam and Jordan are regulars on the first three Thursdays (October 15, 22 and 29). */
    private function regularsOnThreeThursdays(): void
    {
        app(AddRegular::class)->handle(null, $this->slot, $this->sam, '2026-10-15', '2026-10-29');
        app(AddRegular::class)->handle(null, $this->slot, $this->jordan, '2026-10-15', '2026-10-29');
        // Forget the "now a regular" emails.
        Notification::fake();
    }

    private function shiftOn(string $date): Shift
    {
        return Shift::query()->where('weekly_slot_id', $this->slot->id)->whereDate('local_date', $date)->firstOrFail();
    }

    private function slotAttributes(array $changes = []): array
    {
        return $changes + [
            'weekday' => $this->slot->weekday, 'start_time' => '18:30', 'end_time' => '21:30',
            'people_needed' => 2, 'shadow_spots' => 0, 'frequency' => $this->slot->frequency->value,
            'valid_from' => $this->slot->valid_from->toDateString(), 'valid_until' => $this->slot->valid_until?->toDateString(),
            'title' => null, 'is_rotation' => false,
        ];
    }

    private function moveSlot(): void
    {
        app(UpdateWeeklySlot::class)->handle($this->coordinator, $this->slot->fresh(), $this->slotAttributes(['start_time' => '17:00', 'end_time' => '20:00']));
    }

    /** The email's text, with the HTML entities turned back into characters. */
    private function text(ShiftTimeChangedNotification $notification, User $to): string
    {
        return html_entity_decode((string) $notification->toMail($to)->render(), ENT_QUOTES);
    }

    public function test_a_time_change_creates_one_notice_per_person_per_shift_in_one_batch(): void
    {
        $this->regularsOnThreeThursdays();

        $this->moveSlot();

        $this->assertSame(6, ChangeNotice::query()->count());
        $this->assertSame(2, ChangeNotice::query()->distinct()->count('batch'));

        foreach ([$this->sam, $this->jordan] as $person) {
            $rows = ChangeNotice::query()->where('user_id', $person->id)->get();
            $this->assertCount(3, $rows);
            $this->assertCount(1, $rows->pluck('batch')->unique());
            $this->assertSame(26, strlen($rows->first()->batch));
            $this->assertSame($this->coordinator->id, $rows->first()->changed_by);
        }

        $first = ChangeNotice::query()->where('user_id', $this->sam->id)->where('shift_id', $this->shiftOn('2026-10-15')->id)->sole();
        $this->assertSame('18:30', $first->was_starts_at->setTimezone(self::TIMEZONE)->format('H:i'));
        $this->assertSame('21:30', $first->was_ends_at->setTimezone(self::TIMEZONE)->format('H:i'));
        $this->assertNull($first->confirmed_at);
        $this->assertNull($first->ended_at);

        $entries = ChangeLogEntry::query()->ofAction('change_notice.sent')->get();
        $this->assertCount(2, $entries);
        $entry = $entries->firstWhere('affected_user_id', $this->sam->id);
        $this->assertSame($this->coordinator->id, $entry->actor_id);
        $this->assertSame($first->batch, $entry->details['batch']);
        $this->assertSame(3, $entry->details['count']);
        $this->assertCount(3, $entry->details['shift_ids']);
        $this->assertNull($entry->changes);
        $this->assertStringNotContainsString('Sam', json_encode($entry->details));

        Notification::assertSentToTimes($this->sam, ShiftTimeChangedNotification::class, 1);
        Notification::assertSentToTimes($this->jordan, ShiftTimeChangedNotification::class, 1);
        Notification::assertNotSentTo($this->sam, ShiftChangedNotification::class);

        $viewer = User::factory()->create();
        app(GrantRole::class)->handle(null, $viewer, Role::Admin, null);
        $this->assertStringStartsWith('Asked Sam Example whether the new time works for', Describe::entry($entry, $viewer));
    }

    public function test_a_title_only_change_creates_no_notice_and_sends_the_plain_email(): void
    {
        $this->regularsOnThreeThursdays();

        app(UpdateWeeklySlot::class)->handle($this->coordinator, $this->slot->fresh(), $this->slotAttributes(['title' => 'Open house']));

        $this->assertSame(0, ChangeNotice::query()->count());
        $this->assertSame(0, ChangeLogEntry::query()->ofAction('change_notice.sent')->count());
        Notification::assertSentToTimes($this->sam, ShiftChangedNotification::class, 1);
        Notification::assertNotSentTo($this->sam, ShiftTimeChangedNotification::class);

        $shift = $this->shiftOn('2026-10-15');
        app(UpdateShift::class)->handle($this->coordinator, $shift, ['title' => 'Tour night']);
        $this->assertSame(0, ChangeNotice::query()->count());
        Notification::assertSentToTimes($this->sam, ShiftChangedNotification::class, 2);
    }

    public function test_people_needed_only_creates_nothing_and_emails_nobody(): void
    {
        $this->regularsOnThreeThursdays();

        app(UpdateWeeklySlot::class)->handle($this->coordinator, $this->slot->fresh(), $this->slotAttributes(['people_needed' => 3]));
        app(UpdateShift::class)->handle($this->coordinator, $this->shiftOn('2026-10-15'), ['people_needed' => 4]);

        $this->assertSame(0, ChangeNotice::query()->count());
        $this->assertSame(0, ChangeLogEntry::query()->ofAction('change_notice.sent')->count());
        Notification::assertNothingSent();
    }

    public function test_a_newer_change_ends_the_open_notice_on_that_shift(): void
    {
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, '2026-10-22', '18:00', '21:00', 2, 0, null);
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $this->sam->id, 'type' => AssignmentType::Regular]);

        app(UpdateShift::class)->handle($this->coordinator, $shift, ['start_time' => '17:00', 'end_time' => '20:00']);
        app(UpdateShift::class)->handle($this->coordinator, $shift, ['start_time' => '16:00', 'end_time' => '19:00']);

        $rows = ChangeNotice::query()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertNotNull($rows[0]->ended_at);
        $this->assertNull($rows[1]->ended_at);
        $this->assertSame(1, ChangeNotice::query()->open()->count());
        $this->assertNotSame($rows[0]->batch, $rows[1]->batch);
        // The second notice carries over the first one's "was": the last time the person was asked about, not the unanswered 5pm in between.
        $this->assertSame('18:00', $rows[1]->was_starts_at->setTimezone(self::TIMEZONE)->format('H:i'));
        $this->assertSame('21:00', $rows[1]->was_ends_at->setTimezone(self::TIMEZONE)->format('H:i'));
        $second = Notification::sent($this->sam, ShiftTimeChangedNotification::class)->last();
        $this->assertStringContainsString('4 to 7pm (was 6 to 9pm)', $this->text($second, $this->sam));
        $this->assertStringNotContainsString('(was 5 to 8pm)', $this->text($second, $this->sam));
    }

    public function test_undoing_a_change_before_answering_asks_nothing_and_sends_no_email(): void
    {
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, '2026-10-22', '18:00', '21:00', 2, 0, null);
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $this->sam->id, 'type' => AssignmentType::Regular]);

        app(UpdateShift::class)->handle($this->coordinator, $shift, ['start_time' => '19:00', 'end_time' => '22:00']);
        app(UpdateShift::class)->handle($this->coordinator, $shift, ['start_time' => '18:00', 'end_time' => '21:00']);

        $this->assertSame(0, ChangeNotice::query()->open()->count());
        $this->assertSame(1, ChangeNotice::query()->count());
        Notification::assertSentToTimes($this->sam, ShiftTimeChangedNotification::class, 1);
        $this->assertSame(1, ChangeLogEntry::query()->where('action', 'change_notice.sent')->count());

        $this->actingAs($this->coordinator)->get(route('calendar.shift', $shift))->assertOk()
            ->assertSee('Sam E.')->assertDontSee('Hasn\'t answered yet', false);
    }

    public function test_a_second_change_to_a_different_time_still_asks_with_the_last_asked_time(): void
    {
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, '2026-10-22', '18:00', '21:00', 2, 0, null);
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $this->sam->id, 'type' => AssignmentType::Regular]);

        app(UpdateShift::class)->handle($this->coordinator, $shift, ['start_time' => '19:00', 'end_time' => '22:00']);
        app(UpdateShift::class)->handle($this->coordinator, $shift, ['start_time' => '20:00', 'end_time' => '23:00']);

        $this->assertSame(1, ChangeNotice::query()->open()->count());
        Notification::assertSentToTimes($this->sam, ShiftTimeChangedNotification::class, 2);
        $second = Notification::sent($this->sam, ShiftTimeChangedNotification::class)->last();
        $this->assertStringContainsString('8 to 11pm (was 6 to 9pm)', $this->text($second, $this->sam));
    }

    public function test_an_undone_shift_is_left_out_of_the_email_about_the_persons_other_changed_shift(): void
    {
        $one = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, '2026-10-22', '18:00', '21:00', 2, 0, null);
        $two = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, '2026-10-24', '18:00', '21:00', 2, 0, null);

        foreach ([$one, $two] as $shift) {
            Assignment::create(['shift_id' => $shift->id, 'user_id' => $this->sam->id, 'type' => AssignmentType::Regular]);
        }
        // Sam is asked about the move of the first shift, then one edit puts it back and moves the second.
        app(UpdateShift::class)->handle($this->coordinator, $one, ['start_time' => '19:00', 'end_time' => '22:00']);
        Notification::fake();

        $oneBefore = $one->fresh();
        $twoBefore = $two->fresh();
        $oneBefore->update(['starts_at' => CarbonImmutable::parse('2026-10-22 18:00', self::TIMEZONE)->utc(), 'ends_at' => CarbonImmutable::parse('2026-10-22 21:00', self::TIMEZONE)->utc()]);
        $twoAfter = $two->fresh();
        $twoAfter->update(['starts_at' => CarbonImmutable::parse('2026-10-24 17:00', self::TIMEZONE)->utc(), 'ends_at' => CarbonImmutable::parse('2026-10-24 20:00', self::TIMEZONE)->utc()]);

        $sent = app(SendChangeNotices::class)->recordLocked($this->coordinator, [
            ['shift' => $one->fresh()->load('area'), 'was_starts_at' => CarbonImmutable::parse('2026-10-22 19:00', self::TIMEZONE)->utc(), 'was_ends_at' => CarbonImmutable::parse('2026-10-22 22:00', self::TIMEZONE)->utc()],
            ['shift' => $twoAfter->load('area'), 'was_starts_at' => $twoBefore->starts_at, 'was_ends_at' => $twoBefore->ends_at],
        ]);

        $this->assertCount(1, $sent);
        $this->assertSame([$two->id], $sent[0]['notification']->shiftIds);
        $text = $this->text($sent[0]['notification'], $this->sam);
        $this->assertStringContainsString('Saturday, October 24: 5 to 8pm (was 6 to 9pm)', $text);
        $this->assertStringNotContainsString('October 22', $text);
        $this->assertSame([$two->id], ChangeNotice::query()->open()->pluck('shift_id')->all());
    }

    public function test_shadows_get_notices_too(): void
    {
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, '2026-10-22', '18:00', '21:00', 2, 1, null);
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $this->sam->id, 'type' => AssignmentType::Shadow]);

        app(UpdateShift::class)->handle($this->coordinator, $shift, ['start_time' => '17:00', 'end_time' => '20:00']);

        $this->assertSame(1, ChangeNotice::query()->where('user_id', $this->sam->id)->count());
        Notification::assertSentTo($this->sam, ShiftTimeChangedNotification::class);
    }

    public function test_people_who_cant_sign_in_get_no_notice(): void
    {
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, '2026-10-22', '18:00', '21:00', 3, 0, null);
        $notInvited = User::factory()->notInvited()->create();
        $off = User::factory()->create(['deactivated_at' => now()]);

        foreach ([$notInvited, $off, $this->sam] as $person) {
            Assignment::create(['shift_id' => $shift->id, 'user_id' => $person->id, 'type' => AssignmentType::Regular]);
        }

        app(UpdateShift::class)->handle($this->coordinator, $shift, ['start_time' => '17:00', 'end_time' => '20:00']);

        $this->assertSame([$this->sam->id], ChangeNotice::query()->pluck('user_id')->all());
        $this->assertSame(1, ChangeLogEntry::query()->ofAction('change_notice.sent')->count());
        Notification::assertSentTimes(ShiftTimeChangedNotification::class, 1);
    }

    public function test_the_email_has_both_buttons_and_the_old_and_new_times(): void
    {
        $this->regularsOnThreeThursdays();

        $this->moveSlot();

        $sent = Notification::sent($this->sam, ShiftTimeChangedNotification::class)->sole();
        $batch = ChangeNotice::query()->where('user_id', $this->sam->id)->value('batch');
        $text = $this->text($sent, $this->sam);

        $this->assertSame('Your Front desk shift moved to 5 to 8pm', $sent->subject);
        $this->assertStringContainsString('Hi Sam,', $text);
        $this->assertStringContainsString('Coordinator E. changed the time of your Front desk shifts.', $text);
        $this->assertStringContainsString('Thursdays from October 15 to October 29: 5 to 8pm (was 6:30 to 9:30pm)', $text);
        $this->assertStringContainsString('Does the new time still work for you?', $text);
        $this->assertStringContainsString('Still works for me', $text);
        $this->assertStringContainsString("I can't make the new time", $text);
        $this->assertStringContainsString('href="'.route('changes.show', $batch).'"', $text);
        // "I can't make the new time" opens the answer page, which links to Can't make it for the earliest shift still ahead, so it can't go stale a week later.
        $this->assertSame($sent->stillWorksUrl, $sent->cantUrl);
        $this->assertStringNotContainsString(route('shifts.release', $this->shiftOn('2026-10-15')), $text);
        $this->assertStringContainsString("You'll log in first if you aren't already.", $text);
        $this->assertStringNotContainsString('Coordinator Example', $text);
        $this->assertStringNotContainsString('Jordan', $text);
    }

    public function test_the_email_leaves_out_the_lead_check_when_the_first_shift_starts_within_48_hours(): void
    {
        $this->regularsOnThreeThursdays();
        $this->moveSlot();

        // October 15 at 5pm is 32 hours away, so no heads-up will go out.
        $soon = $this->text(Notification::sent($this->sam, ShiftTimeChangedNotification::class)->sole(), $this->sam);
        $this->assertStringNotContainsString('your shift lead will check with you', $soon);

        Notification::fake();
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, '2026-11-10', '18:00', '21:00', 2, 0, null);
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $this->sam->id, 'type' => AssignmentType::Regular]);
        app(UpdateShift::class)->handle($this->coordinator, $shift, ['start_time' => '17:00', 'end_time' => '20:00']);

        $later = $this->text(Notification::sent($this->sam, ShiftTimeChangedNotification::class)->sole(), $this->sam);
        $this->assertStringContainsString("You'll log in first if you aren't already. If you don't answer, your shift lead will check with you before the shift.", $later);
    }

    public function test_a_shift_that_would_move_to_a_start_that_has_already_passed_keeps_its_times_and_is_listed_as_kept(): void
    {
        $this->regularsOnThreeThursdays();
        // Thursday, October 15, noon: today's 6:30pm shift is still ahead.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00', self::TIMEZONE));
        Notification::fake();

        $result = app(UpdateWeeklySlot::class)->handle($this->coordinator, $this->slot->fresh(), $this->slotAttributes(['start_time' => '10:00', 'end_time' => '13:00']), CarbonImmutable::parse('2026-10-15', self::TIMEZONE));

        $today = $this->shiftOn('2026-10-15');
        $this->assertSame('18:30', $today->starts_at->setTimezone(self::TIMEZONE)->format('H:i'));
        $this->assertSame('21:30', $today->ends_at->setTimezone(self::TIMEZONE)->format('H:i'));
        $this->assertSame([$today->id], $result->keptShifts->modelKeys());
        $this->assertSame(UpdateWeeklySlot::KEPT_TIME_PASSED, $result->keptReasons[$today->id]);
        $this->assertSame('The new time has already passed', $result->keptReasons[$today->id]);
        $this->assertSame('10:00', $this->shiftOn('2026-10-22')->starts_at->setTimezone(self::TIMEZONE)->format('H:i'));
        $this->assertSame(7, $result->updatedShifts);

        // Nobody is asked about a time that never applied; the later dates get one notice each.
        $this->assertSame(0, ChangeNotice::query()->where('shift_id', $today->id)->count());
        $this->assertSame(2, ChangeNotice::query()->where('user_id', $this->sam->id)->count());
        Notification::assertSentToTimes($this->sam, ShiftTimeChangedNotification::class, 1);
        Notification::assertSentToTimes($this->jordan, ShiftTimeChangedNotification::class, 1);
    }

    public function test_the_notice_for_a_shift_replaced_by_a_newer_change_keeps_the_original_slot_time_through_a_slot_edit(): void
    {
        $this->regularsOnThreeThursdays();
        $this->moveSlot();
        app(UpdateWeeklySlot::class)->handle($this->coordinator, $this->slot->fresh(), $this->slotAttributes(['start_time' => '16:00', 'end_time' => '19:00']));

        $rows = ChangeNotice::query()->where('user_id', $this->sam->id)->open()->get();
        $this->assertCount(3, $rows);
        $this->assertSame(['18:30'], $rows->map(fn (ChangeNotice $row) => $row->was_starts_at->setTimezone(self::TIMEZONE)->format('H:i'))->unique()->all());
    }

    public function test_the_email_lists_each_date_when_the_shifts_are_not_one_slot_with_one_change(): void
    {
        $one = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, '2026-10-22', '18:00', '21:00', 2, 0, null);
        $two = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, '2026-10-23', '18:00', '21:00', 2, 0, null);
        $notification = new ShiftTimeChangedNotification('01HZZZZZZZZZZZZZZZZZZZZZZZ', collect([
            ['shift' => $two->load('area'), 'was_starts_at' => $two->starts_at, 'was_ends_at' => $two->ends_at],
            ['shift' => $one->load('area'), 'was_starts_at' => $one->starts_at->subHour(), 'was_ends_at' => $one->ends_at],
        ]), null);
        $text = $this->text($notification, $this->sam);

        $this->assertSame('Your Front desk shift moved to 6 to 9pm', $notification->subject);
        $this->assertStringContainsString('The Front desk schedule changed.', $text);
        $this->assertStringContainsString('Thursday, October 22: 6 to 9pm (was 5 to 9pm)', $text);
        $this->assertStringContainsString('Friday, October 23: 6 to 9pm (was 6 to 9pm)', $text);
        $this->assertStringNotContainsString('Thursdays from', $text);
    }

    public function test_releasing_a_shift_ends_your_notice_on_it(): void
    {
        $this->regularsOnThreeThursdays();
        $this->moveSlot();
        $shift = $this->shiftOn('2026-10-22');

        app(ReleaseShifts::class)->one($this->sam, $shift, null);

        $this->assertNotNull(ChangeNotice::query()->where('user_id', $this->sam->id)->where('shift_id', $shift->id)->sole()->ended_at);
        $this->assertSame(2, ChangeNotice::query()->open()->where('user_id', $this->sam->id)->count());
        $this->assertSame(3, ChangeNotice::query()->open()->where('user_id', $this->jordan->id)->count());
    }

    public function test_removing_someone_from_a_shift_ends_their_notice_on_it(): void
    {
        $this->regularsOnThreeThursdays();
        $this->moveSlot();
        $shift = $this->shiftOn('2026-10-22');
        $assignment = Assignment::query()->where('shift_id', $shift->id)->where('user_id', $this->sam->id)->sole();

        app(RemoveFromShift::class)->one($this->coordinator, $shift, $assignment);

        $this->assertNotNull(ChangeNotice::query()->where('user_id', $this->sam->id)->where('shift_id', $shift->id)->sole()->ended_at);
        $this->assertSame(2, ChangeNotice::query()->open()->where('user_id', $this->sam->id)->count());
        $this->assertNull(ChangeNotice::query()->where('user_id', $this->jordan->id)->where('shift_id', $shift->id)->sole()->ended_at);
    }

    public function test_ending_a_regular_ends_their_notices_on_the_shifts_they_left(): void
    {
        $regular = app(AddRegular::class)->handle(null, $this->slot, $this->sam, '2026-10-15', '2026-10-29');
        $this->moveSlot();

        app(EndRegular::class)->handle($this->coordinator, $regular, '2026-10-15');

        $rows = ChangeNotice::query()->where('user_id', $this->sam->id)->get()->keyBy('shift_id');
        $this->assertNull($rows[$this->shiftOn('2026-10-15')->id]->ended_at);
        $this->assertNotNull($rows[$this->shiftOn('2026-10-22')->id]->ended_at);
        $this->assertNotNull($rows[$this->shiftOn('2026-10-29')->id]->ended_at);
    }

    public function test_started_and_cancelled_shifts_get_no_notice(): void
    {
        $this->regularsOnThreeThursdays();
        $cancelled = $this->shiftOn('2026-10-22');
        $cancelled->update(['cancelled_at' => now(), 'cancel_reason' => 'Closed']);
        // Thursday, October 15, 7pm: the first shift is under way.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 19:00', self::TIMEZONE));

        $this->moveSlot();

        $this->assertSame([$this->shiftOn('2026-10-29')->id], ChangeNotice::query()->where('user_id', $this->sam->id)->pluck('shift_id')->all());
        $this->assertSame(0, ChangeNotice::query()->where('shift_id', $this->shiftOn('2026-10-15')->id)->count());
        $this->assertSame(0, ChangeNotice::query()->where('shift_id', $cancelled->id)->count());
    }

    public function test_a_consecutive_run_of_one_slot_reads_as_a_range_and_a_gap_lists_each_date(): void
    {
        $dates = ['2026-10-15', '2026-10-22', '2026-10-29'];
        $item = fn (string $date) => ['shift' => $this->shiftOn($date)->load('area'), 'was_starts_at' => $this->shiftOn($date)->starts_at->subHour(), 'was_ends_at' => $this->shiftOn($date)->ends_at];

        $run = new ShiftTimeChangedNotification('01HZZZZZZZZZZZZZZZZZZZZZZZ', collect(array_map($item, $dates)), null);
        $this->assertSame(['Thursdays from October 15 to October 29: 6:30 to 9:30pm (was 5:30 to 9:30pm)'], $run->lines);

        // October 22 isn't in the batch, so "Thursdays from October 15 to October 29" would be wrong.
        $gap = new ShiftTimeChangedNotification('01HZZZZZZZZZZZZZZZZZZZZZZZ', collect(array_map($item, ['2026-10-15', '2026-10-29'])), null);
        $this->assertSame([
            'Thursday, October 15: 6:30 to 9:30pm (was 5:30 to 9:30pm)',
            'Thursday, October 29: 6:30 to 9:30pm (was 5:30 to 9:30pm)',
        ], $gap->lines);
    }

    public function test_a_slot_edit_with_a_time_change_and_a_title_only_change_sends_one_email_with_both(): void
    {
        app(AddRegular::class)->handle(null, $this->slot, $this->sam, '2026-10-15', '2026-10-22');
        // October 22 is already at the new time, so the edit changes only its title.
        $already = $this->shiftOn('2026-10-22');
        $already->update(['starts_at' => CarbonImmutable::parse('2026-10-22 17:00', self::TIMEZONE)->utc(), 'ends_at' => CarbonImmutable::parse('2026-10-22 20:00', self::TIMEZONE)->utc()]);
        Notification::fake();

        app(UpdateWeeklySlot::class)->handle($this->coordinator, $this->slot->fresh(), $this->slotAttributes(['start_time' => '17:00', 'end_time' => '20:00', 'title' => 'Open house']));

        Notification::assertSentToTimes($this->sam, ShiftTimeChangedNotification::class, 1);
        Notification::assertNotSentTo($this->sam, ShiftChangedNotification::class);
        $this->assertSame([$this->shiftOn('2026-10-15')->id], ChangeNotice::query()->where('user_id', $this->sam->id)->pluck('shift_id')->all());

        $sent = Notification::sent($this->sam, ShiftTimeChangedNotification::class)->sole();
        $this->assertSame(["Thursday, October 15: 5 to 8pm (was 6:30 to 9:30pm). It's now for Open house."], $sent->lines);
        $this->assertSame(["Your Front desk shift on Thursday, October 22 changed. It's now for Open house."], $sent->titleLines);

        $text = $this->text($sent, $this->sam);
        $this->assertLessThan(strpos($text, "Your Front desk shift on Thursday, October 22 changed. It's now for Open house."), strpos($text, 'Does the new time still work for you?'));
        $this->assertStringContainsString("Thursday, October 15: 5 to 8pm (was 6:30 to 9:30pm). It's now for Open house.", $text);
    }

    public function test_a_shift_edit_that_changes_the_time_and_the_title_mentions_both(): void
    {
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, '2026-10-22', '18:00', '21:00', 2, 0, 'Tour night');
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $this->sam->id, 'type' => AssignmentType::Regular]);

        app(UpdateShift::class)->handle($this->coordinator, $shift, ['start_time' => '17:00', 'end_time' => '20:00', 'title' => '']);

        Notification::assertNotSentTo($this->sam, ShiftChangedNotification::class);
        $sent = Notification::sent($this->sam, ShiftTimeChangedNotification::class)->sole();
        $this->assertSame(["Thursday, October 22: 5 to 8pm (was 6 to 9pm). It's no longer for Tour night."], $sent->lines);
        $this->assertStringContainsString("It's no longer for Tour night.", $this->text($sent, $this->sam));
    }

    public function test_started_cancelled_and_already_started_shifts_are_skipped_directly(): void
    {
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, '2026-10-22', '18:00', '21:00', 2, 0, null)->load('area');
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $this->sam->id, 'type' => AssignmentType::Regular]);
        $was = ['was_starts_at' => $shift->starts_at->subHour(), 'was_ends_at' => $shift->ends_at];
        $notices = app(SendChangeNotices::class);

        $cancelled = $shift->replicate();
        $cancelled->id = $shift->id;
        $cancelled->cancelled_at = now();
        $this->assertSame([], $notices->recordLocked($this->coordinator, [['shift' => $cancelled] + $was]));

        $started = $shift->replicate();
        $started->id = $shift->id;
        $started->starts_at = now()->subHour();
        $this->assertSame([], $notices->recordLocked($this->coordinator, [['shift' => $started] + $was]));

        // The old start has passed, the new one hasn't: a shift that was under way can't have been moved.
        $this->assertSame([], $notices->recordLocked($this->coordinator, [['shift' => $shift, 'was_starts_at' => now()->subHour(), 'was_ends_at' => now()->addHour()]]));

        $this->assertSame(0, ChangeNotice::query()->count());
        $this->assertSame(0, ChangeLogEntry::query()->ofAction('change_notice.sent')->count());
        $this->assertCount(1, $notices->recordLocked($this->coordinator, [['shift' => $shift] + $was]));
    }

    public function test_turning_someone_off_ends_their_open_notices(): void
    {
        $this->regularsOnThreeThursdays();
        $this->moveSlot();

        app(DeactivateUser::class)->handle($this->coordinator, $this->sam);

        $this->assertSame(3, ChangeNotice::query()->where('user_id', $this->sam->id)->whereNotNull('ended_at')->count());
        $this->assertSame(0, ChangeNotice::query()->open()->where('user_id', $this->sam->id)->count());
        $this->assertSame(3, ChangeNotice::query()->open()->where('user_id', $this->jordan->id)->count());
    }
}
