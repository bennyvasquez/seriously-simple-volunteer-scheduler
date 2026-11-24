<?php

namespace Tests\Feature\Scheduling;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\CancelShift;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\PutOnShift;
use App\Actions\Scheduling\SendChangeHeadsUps;
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
use App\Notifications\NoAnswerLeadNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class ChangeHeadsUpTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private Area $frontDesk;

    private User $coordinator;

    private User $sam;

    private User $jordan;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->withoutDefer();
        // Tuesday, October 20, 2026, 9am in Detroit.
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00', self::TIMEZONE));
        Organization::factory()->create(['timezone' => self::TIMEZONE, 'weeks_ahead' => 8]);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk', 'lead_name' => 'Shift lead']);
        $this->coordinator = $this->withRole('Chris Coordinator', Role::Coordinator);
        $this->sam = User::factory()->cleared()->create(['name' => 'Sam Example']);
        $this->jordan = User::factory()->cleared()->create(['name' => 'Jordan Example']);
        // Thursday, October 22, 6:30 to 9:30pm: about 57 hours from now, so 48 hours out is Tuesday night.
        $this->shift = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, '2026-10-22', '18:30', '21:30', 2, 1, null);
        app(PutOnShift::class)->handle($this->coordinator, $this->shift, $this->sam, AssignmentType::Regular);
        app(PutOnShift::class)->handle($this->coordinator, $this->shift, $this->jordan, AssignmentType::Regular);
        Notification::fake();
    }

    private function withRole(string $name, Role $role, ?Area $area = null): User
    {
        $user = User::factory()->create(['name' => $name]);
        app(GrantRole::class)->handle(null, $user, $role, $area);
        $this->enableTwoFactor($user);

        return $user;
    }

    /** An open notice for the person on the shift, made `$hoursAgo` hours before now. */
    private function notice(User $person, ?Shift $shift = null, int $hoursAgo = 24): ChangeNotice
    {
        $shift ??= $this->shift;
        $notice = ChangeNotice::create([
            'batch' => (string) Str::ulid(), 'user_id' => $person->id, 'shift_id' => $shift->id, 'changed_by' => $this->coordinator->id,
            'was_starts_at' => $shift->starts_at->subHour(), 'was_ends_at' => $shift->ends_at->subHour(),
        ]);
        $notice->forceFill(['created_at' => now()->subHours($hoursAgo)])->save();

        return $notice;
    }

    private function run48(): int
    {
        return app(SendChangeHeadsUps::class)->run();
    }

    public function test_unanswered_people_are_named_to_the_leads_once(): void
    {
        $lead = $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $other = $this->withRole('Jamie Lead', Role::AreaLead, Area::factory()->create());
        $this->notice($this->sam);
        $this->notice($this->jordan);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 19:00', self::TIMEZONE));

        $this->assertSame(1, $this->run48());

        Notification::assertSentToTimes($lead, NoAnswerLeadNotification::class, 1);
        Notification::assertNotSentTo($other, NoAnswerLeadNotification::class);
        Notification::assertNotSentTo($this->coordinator, NoAnswerLeadNotification::class);
        Notification::assertSentTo($lead, NoAnswerLeadNotification::class, function (NoAnswerLeadNotification $n) use ($lead) {
            $mail = $n->toMail($lead);
            $text = implode("\n", array_merge($mail->introLines, $mail->outroLines));

            return $mail->subject === 'No answer yet: Front desk, Thursday, October 22'
                && $mail->greeting === 'Hi Alex,'
                && $n->names === ['Jordan E.', 'Sam E.']
                && str_contains($text, 'Front desk, 6:30 to 9:30pm (was 5:30 to 8:30pm)')
                && str_contains($text, 'Jordan E., Sam E.')
                && $mail->actionText === 'Open the shift'
                && $mail->actionUrl === route('calendar.shift', $this->shift)
                && str_contains($text, 'You\'re getting this because you\'re a shift lead for Front desk.');
        });
        $this->assertSame(2, ChangeNotice::query()->whereNotNull('lead_told_at')->count());
    }

    public function test_running_again_sends_nothing(): void
    {
        $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $this->notice($this->sam);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 19:00', self::TIMEZONE));

        $this->assertSame(1, $this->run48());
        $this->assertSame(0, $this->run48());

        Notification::assertSentTimes(NoAnswerLeadNotification::class, 1);
        $this->assertSame(1, ChangeLogEntry::query()->where('action', 'change_notice.lead_told')->count());
    }

    public function test_a_change_inside_48_hours_sends_no_heads_up(): void
    {
        $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        // Made 40 hours before the shift starts.
        $this->travelTo($this->shift->starts_at->subHours(40));
        $this->notice($this->sam, hoursAgo: 0);

        $this->assertSame(0, $this->run48());

        Notification::assertNothingSent();
        $this->assertNull(ChangeNotice::query()->first()->lead_told_at);
    }

    public function test_people_who_left_the_shift_arent_named(): void
    {
        $lead = $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $this->notice($this->sam);
        $this->notice($this->jordan);
        // Leaving without ending the notice, to pin the assignment check itself.
        Assignment::query()->where('user_id', $this->sam->id)->delete();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 19:00', self::TIMEZONE));

        $this->assertSame(1, $this->run48());

        Notification::assertSentTo($lead, NoAnswerLeadNotification::class, fn (NoAnswerLeadNotification $n) => $n->names === ['Jordan E.']);
    }

    public function test_confirmed_people_arent_named(): void
    {
        $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $this->notice($this->sam)->update(['confirmed_at' => now()]);
        $this->notice($this->jordan);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 19:00', self::TIMEZONE));

        $this->assertSame(1, $this->run48());

        Notification::assertSentTo(User::query()->where('name', 'Alex Lead')->first(), NoAnswerLeadNotification::class, fn (NoAnswerLeadNotification $n) => $n->names === ['Jordan E.']);

        $this->travelTo(CarbonImmutable::parse('2026-10-20 19:30', self::TIMEZONE));
        ChangeNotice::query()->where('user_id', $this->jordan->id)->update(['confirmed_at' => now(), 'lead_told_at' => null]);
        Notification::fake();

        $this->assertSame(0, $this->run48());
        Notification::assertNothingSent();
    }

    public function test_coordinators_hear_when_the_area_has_no_lead(): void
    {
        $second = $this->withRole('Dana Coordinator', Role::Coordinator);
        $lead = $this->withRole('Alex Lead', Role::AreaLead, Area::factory()->create());
        $this->notice($this->sam);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 19:00', self::TIMEZONE));

        $this->assertSame(1, $this->run48());

        Notification::assertSentToTimes($this->coordinator, NoAnswerLeadNotification::class, 1);
        Notification::assertSentToTimes($second, NoAnswerLeadNotification::class, 1);
        Notification::assertNotSentTo($lead, NoAnswerLeadNotification::class);
        Notification::assertSentTo($second, NoAnswerLeadNotification::class, fn (NoAnswerLeadNotification $n) => str_contains(implode("\n", $n->toMail($second)->outroLines), 'because you\'re a coordinator.'));
    }

    public function test_a_cancelled_or_started_shift_sends_nothing(): void
    {
        $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $this->notice($this->sam);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 19:00', self::TIMEZONE));
        app(CancelShift::class)->handle($this->coordinator, $this->shift, 'Closed for repairs');
        Notification::fake();

        $this->assertSame(0, $this->run48());
        Notification::assertNothingSent();

        $started = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, '2026-10-21', '09:00', '10:00', 1, 1, null);
        app(PutOnShift::class)->handle($this->coordinator, $started, $this->sam, AssignmentType::Regular);
        $this->notice($this->sam, $started, 30);
        $this->travelTo($started->starts_at->addMinutes(5));
        Notification::fake();

        $this->assertSame(0, $this->run48());
        Notification::assertNothingSent();
    }

    public function test_a_shift_cancelled_with_an_open_notice_sends_nothing(): void
    {
        $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $this->notice($this->sam);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 19:00', self::TIMEZONE));
        // Set directly, not through the cancel action (which ends notices), to pin the cancelled check itself.
        $this->shift->forceFill(['cancelled_at' => now()])->save();
        $this->assertNotNull(ChangeNotice::query()->open()->first());

        $this->assertSame(0, $this->run48());

        Notification::assertNothingSent();
        $this->assertNull(ChangeNotice::query()->first()->lead_told_at);
    }

    public function test_a_lead_who_cant_sign_in_is_skipped_and_the_coordinators_are_emailed(): void
    {
        // Not invited yet, so they can't sign in: that's "no active lead" for this email.
        $lead = $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $lead->forceFill(['invited_at' => null])->save();
        $this->notice($this->sam);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 19:00', self::TIMEZONE));

        $this->assertSame(1, $this->run48());

        Notification::assertNotSentTo($lead, NoAnswerLeadNotification::class);
        Notification::assertSentToTimes($this->coordinator, NoAnswerLeadNotification::class, 1);
    }

    public function test_it_is_logged_as_the_system(): void
    {
        $this->notice($this->sam);
        $this->notice($this->jordan);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 19:00', self::TIMEZONE));

        $this->run48();

        $entry = ChangeLogEntry::query()->where('action', 'change_notice.lead_told')->sole();
        $this->assertNull($entry->actor_id);
        $this->assertNull($entry->actor_name);
        $this->assertSame($this->shift->id, $entry->subject_id);
        $this->assertSame(['shift_id' => $this->shift->id, 'count' => 2], $entry->details);
        $this->assertStringNotContainsString('Sam', json_encode($entry->details));
        $this->assertSame('Told the leads that 2 people haven\'t answered about the new time for the Front desk shift on Thursday, October 22, 6:30 to 9:30pm', Describe::entry($entry, $this->coordinator));
    }
}
