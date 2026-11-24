<?php

namespace Tests\Feature\Scheduling;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\CancelShift;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\PutOnShift;
use App\Actions\Scheduling\SendShiftReminders;
use App\ChangeLog\Describe;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\ChangeNotice;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\ShiftReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShiftRemindersTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private Area $frontDesk;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->withoutDefer();
        // Monday, October 19, 2026, 9am in Detroit: everyone is put on shifts now, and each test travels to the time it wants.
        $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', self::TIMEZONE));
        Organization::factory()->create(['timezone' => self::TIMEZONE, 'weeks_ahead' => 8, 'reminder_hours' => 24]);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk']);
        $this->coordinator = User::factory()->create(['name' => 'Chris Coordinator']);
        app(GrantRole::class)->handle(null, $this->coordinator, Role::Coordinator);
    }

    private function person(string $name, array $attributes = []): User
    {
        return User::factory()->cleared()->create(['name' => $name] + $attributes);
    }

    /** A shift on a local date, with the people put on it (as regular unless given as [person, type]). */
    private function shiftWith(array $people, string $date = '2026-10-21', string $from = '09:00', string $to = '12:00', ?string $title = null): Shift
    {
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, $date, $from, $to, 4, 1, $title);

        foreach ($people as $person) {
            [$user, $type] = is_array($person) ? $person : [$person, AssignmentType::Regular];
            app(PutOnShift::class)->handle($this->coordinator, $shift, $user, $type);
        }

        Notification::fake();

        return $shift;
    }

    private function at(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse($time, self::TIMEZONE));
    }

    private function run24(): int
    {
        return app(SendShiftReminders::class)->run();
    }

    private function text(ShiftReminderNotification $notification, User $to): string
    {
        $html = (string) $notification->toMail($to)->render();

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES)));
    }

    private function sentTo(User $user): ?ShiftReminderNotification
    {
        return Notification::sent($user, ShiftReminderNotification::class)->first();
    }

    public function test_everyone_on_the_shift_is_reminded_once(): void
    {
        $regular = $this->person('Sam Example');
        $taker = $this->person('Jordan Example');
        $shadow = User::factory()->create(['name' => 'Casey Example']);
        $shift = $this->shiftWith([$regular, $taker, [$shadow, AssignmentType::Shadow]]);
        $this->at('2026-10-20 10:00');

        $this->assertSame(3, $this->run24());
        $this->assertSame(0, $this->run24());

        foreach ([$regular, $taker, $shadow] as $person) {
            Notification::assertSentToTimes($person, ShiftReminderNotification::class, 1);
        }

        $this->assertSame(0, $shift->assignments()->whereNull('reminded_at')->count());
        $entries = ChangeLogEntry::query()->where('action', 'shift.reminded')->get();
        $this->assertCount(1, $entries);
        $this->assertNull($entries->first()->actor_id);
        $this->assertSame(['shift_id' => $shift->id, 'count' => 3], $entries->first()->details);
        $this->assertStringNotContainsString('Sam', json_encode($entries->first()->details));
    }

    public function test_the_change_log_describes_a_reminder_under_the_schedule_filter(): void
    {
        $this->shiftWith([$this->person('Sam Example')]);
        $this->at('2026-10-20 10:00');
        $this->run24();

        $entry = ChangeLogEntry::query()->where('action', 'shift.reminded')->firstOrFail();

        $this->assertSame('Sent 1 reminder email for the Front desk shift on Wednesday, October 21, 9am to 12pm', Describe::entry($entry, $this->coordinator));
    }

    public function test_nothing_goes_out_before_the_window_or_when_off(): void
    {
        $sam = $this->person('Sam Example');
        $this->shiftWith([$sam]);
        $this->at('2026-10-20 08:00');

        $this->assertSame(0, $this->run24());

        $this->at('2026-10-21 08:00');
        Organization::current()->forceFill(['reminder_hours' => null])->save();

        $this->assertSame(0, $this->run24());
        Notification::assertNothingSent();
    }

    public function test_people_who_cant_sign_in_or_turned_reminders_off_are_skipped(): void
    {
        $sam = $this->person('Sam Example');
        $notInvited = User::factory()->notInvited()->create(['name' => 'Pat Example']);
        $off = $this->person('Robin Example');
        $optedOut = $this->person('Alex Example', ['reminder_emails' => false]);
        $shift = $this->shiftWith([$sam, $notInvited, $off, $optedOut]);
        $off->forceFill(['deactivated_at' => now()])->save();
        $this->at('2026-10-20 10:00');

        $this->assertSame(1, $this->run24());

        Notification::assertSentToTimes($sam, ShiftReminderNotification::class, 1);

        foreach ([$notInvited, $off, $optedOut] as $person) {
            Notification::assertNotSentTo($person, ShiftReminderNotification::class);
        }

        $this->assertSame(0, $shift->assignments()->whereNull('reminded_at')->count());
        $this->assertSame(1, ChangeLogEntry::query()->where('action', 'shift.reminded')->first()->details['count']);
    }

    public function test_nothing_is_logged_when_nobody_is_emailed(): void
    {
        $this->shiftWith([User::factory()->notInvited()->create()]);
        $this->at('2026-10-20 10:00');

        $this->assertSame(0, $this->run24());
        $this->assertSame(0, ChangeLogEntry::query()->where('action', 'shift.reminded')->count());
    }

    public function test_someone_put_on_inside_the_window_is_not_reminded(): void
    {
        $early = $this->person('Sam Example');
        $late = $this->person('Jordan Example');
        $shift = $this->shiftWith([$early]);
        $this->at('2026-10-21 06:00');
        app(PutOnShift::class)->handle($this->coordinator, $shift, $late, AssignmentType::Regular);
        Notification::fake();
        $this->at('2026-10-21 08:00');

        $this->assertSame(1, $this->run24());

        Notification::assertSentToTimes($early, ShiftReminderNotification::class, 1);
        Notification::assertNotSentTo($late, ShiftReminderNotification::class);
    }

    public function test_cancelled_and_started_shifts_are_skipped(): void
    {
        $sam = $this->person('Sam Example');
        $cancelled = $this->shiftWith([$sam], '2026-10-21');
        $started = $this->shiftWith([$sam], '2026-10-20', '08:00', '10:00');
        app(CancelShift::class)->handle($this->coordinator, $cancelled, 'Closed');
        Notification::fake();
        $this->at('2026-10-20 09:00');

        $this->assertSame(0, $this->run24());
        Notification::assertNothingSent();
    }

    public function test_the_email_says_tomorrow_and_the_time_zone(): void
    {
        $sam = $this->person('Sam Example');
        $this->shiftWith([$sam], '2026-10-25', '20:00', '21:00', 'Open mic night');
        // Saturday 8pm in Detroit is already 00:00 on Sunday in UTC.
        $this->at('2026-10-24 20:00');

        $this->assertSame(1, $this->run24());

        $notification = $this->sentTo($sam);
        $this->assertSame('Reminder: Front desk tomorrow, 8 to 9pm', $notification->subject);
        $text = $this->text($notification, $sam);
        $this->assertStringContainsString("Hi Sam, You're on Front desk tomorrow, Sunday, October 25, 8 to 9pm (Eastern Time). Open mic night", $text);
    }

    public function test_the_subject_says_today_or_the_date(): void
    {
        $sam = $this->person('Sam Example');
        $this->shiftWith([$sam], '2026-10-21');
        $this->at('2026-10-21 03:00');
        $this->run24();
        $this->assertSame('Reminder: Front desk today, 9am to 12pm', $this->sentTo($sam)->subject);

        Organization::current()->forceFill(['reminder_hours' => 48])->save();
        $jordan = $this->person('Jordan Example');
        $this->shiftWith([$jordan], '2026-10-24');
        $this->at('2026-10-22 09:00');
        $this->run24();
        $this->assertSame('Reminder: Front desk on Saturday, October 24, 9am to 12pm', $this->sentTo($jordan)->subject);
    }

    public function test_the_email_names_the_others_and_shadows_by_short_name(): void
    {
        $sam = $this->person('Sam Example');
        $jordan = $this->person('Jordan Pine');
        $alex = $this->person('Alex Rivera');
        $casey = User::factory()->create(['name' => 'Casey Moss']);
        $this->shiftWith([$sam, $jordan, $alex, [$casey, AssignmentType::Shadow]]);
        $this->at('2026-10-20 10:00');
        $this->run24();

        $text = $this->text($this->sentTo($sam), $sam);
        $this->assertStringContainsString('Also on this shift: Alex R., Jordan P. Shadowing: Casey M.', $text);
        $this->assertStringNotContainsString('Sam E.', $text);

        $caseyText = $this->text($this->sentTo($casey), $casey);
        $this->assertStringContainsString('Also on this shift: Alex R., Jordan P., Sam E.', $caseyText);
        $this->assertStringNotContainsString('Shadowing:', $caseyText);
    }

    public function test_nobody_else_is_said_when_the_person_is_alone(): void
    {
        $sam = $this->person('Sam Example');
        $this->shiftWith([$sam]);
        $this->at('2026-10-20 10:00');
        $this->run24();

        $this->assertStringContainsString('Nobody else is on this shift yet.', $this->text($this->sentTo($sam), $sam));
    }

    public function test_a_shadow_is_told_theyre_shadowing(): void
    {
        $casey = User::factory()->create(['name' => 'Casey Moss']);
        $this->shiftWith([[$casey, AssignmentType::Shadow]]);
        $this->at('2026-10-20 10:00');
        $this->run24();

        $this->assertStringContainsString("You're shadowing Front desk tomorrow, Wednesday, October 21, 9am to 12pm (Eastern Time).", $this->text($this->sentTo($casey), $casey));
    }

    public function test_an_unanswered_time_change_is_asked_again(): void
    {
        $sam = $this->person('Sam Example');
        $jordan = $this->person('Jordan Example');
        $shift = $this->shiftWith([$sam, $jordan]);
        $notice = ChangeNotice::create([
            'batch' => (string) Str::ulid(), 'user_id' => $sam->id, 'shift_id' => $shift->id, 'changed_by' => $this->coordinator->id,
            'was_starts_at' => $shift->starts_at->subHour(), 'was_ends_at' => $shift->ends_at->subHour(),
        ]);
        $this->at('2026-10-20 10:00');
        $this->run24();

        $html = (string) $this->sentTo($sam)->toMail($sam)->render();
        $url = route('changes.show', $notice->batch);
        $text = $this->text($this->sentTo($sam), $sam);

        $this->assertStringContainsString("The time of this shift changed, and you haven't told us yet whether the new time works.", $text);
        $this->assertSame(2, substr_count($html, 'href="'.$url.'"'));
        $this->assertStringContainsString('Still works for me', $text);
        $this->assertStringContainsString("I can't make the new time", $text);

        $this->assertStringNotContainsString('The time of this shift changed', $this->text($this->sentTo($jordan), $jordan));
    }

    public function test_the_email_has_cant_make_it_and_links_to_the_emails_page(): void
    {
        $sam = $this->person('Sam Example');
        $shift = $this->shiftWith([$sam]);
        $this->at('2026-10-20 10:00');
        $this->run24();

        $mail = $this->sentTo($sam)->toMail($sam);
        $html = (string) $mail->render();

        $this->assertStringContainsString('href="'.route('shifts.release', $shift).'"', $html);
        $this->assertStringContainsString('href="'.route('account.emails').'"', $html);
        $this->assertStringContainsString('You can turn these reminders off on the Emails page.', $this->text($this->sentTo($sam), $sam));
    }

    public function test_someone_taken_off_between_the_search_and_the_lock_gets_nothing(): void
    {
        $sam = $this->person('Sam Example');
        $jordan = $this->person('Jordan Example');
        $shift = $this->shiftWith([$sam, $jordan]);
        $this->at('2026-10-20 10:00');

        $done = false;
        DB::listen(function ($query) use (&$done, $sam, $shift) {
            if ($done || ! str_contains($query->sql, 'from `shifts`') || ! str_contains($query->sql, 'reminded_at')) {
                return;
            }

            $done = true;
            DB::table('assignments')->where('shift_id', $shift->id)->where('user_id', $sam->id)->delete();
        });

        $this->assertSame(1, $this->run24());
        $this->assertTrue($done);

        Notification::assertNotSentTo($sam, ShiftReminderNotification::class);
        Notification::assertSentToTimes($jordan, ShiftReminderNotification::class, 1);
    }

    public function test_a_shift_cancelled_between_the_search_and_the_lock_gets_nothing(): void
    {
        $sam = $this->person('Sam Example');
        $shift = $this->shiftWith([$sam]);
        $this->at('2026-10-20 10:00');

        $done = false;
        DB::listen(function ($query) use (&$done, $shift) {
            if ($done || ! str_contains($query->sql, 'from `shifts`') || ! str_contains($query->sql, 'reminded_at')) {
                return;
            }

            $done = true;
            DB::table('shifts')->where('id', $shift->id)->update(['cancelled_at' => now()]);
        });

        $this->assertSame(0, $this->run24());
        Notification::assertNothingSent();
    }

    public function test_the_command_runs_the_job(): void
    {
        $this->shiftWith([$this->person('Sam Example')]);
        $this->at('2026-10-20 10:00');

        $this->artisan('ssvs:shift-reminders')->expectsOutput('Sent 1 shift reminder.')->assertSuccessful();
    }
}
