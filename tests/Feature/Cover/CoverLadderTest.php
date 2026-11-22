<?php

namespace Tests\Feature\Cover;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\CancelShift;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\PutOnShift;
use App\Actions\Scheduling\RestoreShift;
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
use App\Models\User;
use App\Notifications\CoverRequestNotification;
use App\Notifications\CoverUrgentNotification;
use App\Notifications\ShadowSpotNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class CoverLadderTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private Area $frontDesk;

    private Area $hostedEvents;

    private User $coordinator;

    private User $robin;

    private User $morgan;

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
    }

    private function withRole(string $name, Role $role, ?Area $area = null): User
    {
        $user = User::factory()->create(['name' => $name]);
        app(GrantRole::class)->handle(null, $user, $role, $area);
        $this->enableTwoFactor($user);

        return $user;
    }

    /** Thursday, October 22, 6:30 to 9:30pm on Front desk, released by Robin when the clock says it is now. */
    private function released(int $peopleNeeded = 1, ?string $note = 'The key is in the drawer.'): Shift
    {
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, '2026-10-22', '18:30', '21:30', $peopleNeeded, 1, null);
        CoverRequest::create(['shift_id' => $shift->id, 'released_by' => $this->robin->id, 'note' => $note, 'notified_count' => 3]);

        return $shift;
    }

    private function at(string $localTime): void
    {
        $this->travelTo(CarbonImmutable::parse($localTime, self::TIMEZONE));
    }

    private function on(Shift $shift, User $person, AssignmentType $type = AssignmentType::Regular): void
    {
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $person->id, 'type' => $type]);
    }

    public function test_nothing_happens_before_72_hours(): void
    {
        $shift = $this->released();
        Notification::fake();

        $this->at('2026-10-19 18:29');

        $this->assertSame(['resent' => 0, 'escalated' => 0], app(CoverLadder::class)->run());
        Notification::assertNothingSent();
        $this->assertNull(CoverRequest::query()->where('shift_id', $shift->id)->sole()->resent_at);
    }

    public function test_resends_once_at_72_hours(): void
    {
        $shift = $this->released();
        $request = CoverRequest::query()->where('shift_id', $shift->id)->sole();
        Notification::fake();

        $this->at('2026-10-19 18:30');

        $this->assertSame(['resent' => 1, 'escalated' => 0], app(CoverLadder::class)->run());

        foreach ([$this->morgan, $this->coordinator] as $person) {
            Notification::assertSentToTimes($person, CoverRequestNotification::class, 1);
            Notification::assertSentTo($person, CoverRequestNotification::class, function (CoverRequestNotification $notification) use ($person, $shift) {
                $mail = $notification->toMail($person);
                $lines = implode("\n", array_merge($mail->introLines, $mail->outroLines));

                return $mail->subject === 'Still open: Front desk on Thursday, October 22'
                    && str_contains($lines, 'Nobody has taken Robin\'s Front desk shift yet. It\'s Thursday, October 22, 6:30 to 9:30pm.')
                    && str_contains($lines, 'Note from Robin: The key is in the drawer.')
                    && $mail->actionText === 'Take this shift'
                    && $mail->actionUrl === url('/shifts/'.$shift->id.'/take');
            });
        }
        Notification::assertNotSentTo($this->robin, CoverRequestNotification::class);
        Notification::assertNotSentTo($this->coordinator, CoverUrgentNotification::class);

        $request->refresh();
        $this->assertTrue($request->resent_at->equalTo(now()));
        $this->assertNull($request->escalated_at);
        $entry = ChangeLogEntry::query()->forSubject($shift)->ofAction('cover_request.resent')->sole();
        $this->assertNull($entry->actor_id);
        $this->assertNull($entry->actor_name);
        $this->assertSame(['cover_request_id' => $request->id], $entry->details);
        $this->assertSame(['resent_at' => ['old' => null, 'new' => now()->toDateTimeString()]], $entry->changes);

        $this->at('2026-10-19 18:40');
        $this->assertSame(['resent' => 0, 'escalated' => 0], app(CoverLadder::class)->run());
        Notification::assertSentToTimes($this->morgan, CoverRequestNotification::class, 1);
    }

    public function test_escalates_once_at_24_hours(): void
    {
        $lead = $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $otherLead = $this->withRole('Jamie Lead', Role::AreaLead, $this->hostedEvents);
        $admin = $this->withRole('Dana Admin', Role::Admin);
        $leadAndCoordinator = $this->withRole('Sky Both', Role::Coordinator);
        app(GrantRole::class)->handle(null, $leadAndCoordinator, Role::AreaLead, $this->frontDesk);
        $shift = $this->released();
        $request = CoverRequest::query()->where('shift_id', $shift->id)->sole();
        Notification::fake();

        $this->at('2026-10-21 18:30');

        $this->assertSame(['resent' => 0, 'escalated' => 1], app(CoverLadder::class)->run());

        foreach ([$lead, $this->coordinator, $leadAndCoordinator] as $person) {
            Notification::assertSentToTimes($person, CoverUrgentNotification::class, 1);
        }
        Notification::assertSentTo($lead, CoverUrgentNotification::class, function (CoverUrgentNotification $notification) use ($lead, $shift) {
            $mail = $notification->toMail($lead);
            $lines = implode("\n", array_merge($mail->introLines, $mail->outroLines));

            return $mail->subject === 'Needs someone tomorrow: Front desk, Thursday, October 22'
                && str_contains($lines, 'Nobody has taken this shift yet, and it starts in less than 24 hours.')
                && str_contains($lines, 'Front desk, Thursday, October 22, 6:30 to 9:30pm. Needs 1 more person.')
                && str_contains($lines, 'Robin released it on Wednesday, October 14. We emailed 3 volunteers when it was released.')
                && str_contains($lines, 'It\'s marked urgent on the calendar until someone takes it. From the shift you can put someone on it yourself.')
                && str_contains($lines, 'You\'re getting this because you\'re a shift lead for Front desk.')
                && $mail->actionText === 'Open the shift'
                && $mail->actionUrl === route('calendar.shift', $shift);
        });
        Notification::assertSentTo($this->coordinator, CoverUrgentNotification::class, fn (CoverUrgentNotification $notification) => str_contains(implode("\n", $notification->toMail($this->coordinator)->outroLines), 'You\'re getting this because you\'re a coordinator.'));
        Notification::assertNotSentTo($otherLead, CoverUrgentNotification::class);
        Notification::assertNotSentTo($admin, CoverUrgentNotification::class);
        Notification::assertNotSentTo($this->morgan, CoverUrgentNotification::class);
        Notification::assertNothingSentTo($this->morgan);

        $request->refresh();
        $this->assertTrue($request->escalated_at->equalTo(now()));
        $entry = ChangeLogEntry::query()->forSubject($shift)->ofAction('cover_request.escalated')->sole();
        $this->assertNull($entry->actor_id);
        $this->assertSame(['cover_request_id' => $request->id], $entry->details);
        $this->assertSame(['escalated_at' => ['old' => null, 'new' => now()->toDateTimeString()]], $entry->changes);

        $this->at('2026-10-21 18:40');
        $this->assertSame(['resent' => 0, 'escalated' => 0], app(CoverLadder::class)->run());
        Notification::assertSentToTimes($lead, CoverUrgentNotification::class, 1);
    }

    public function test_the_urgent_email_says_when_the_request_was_sent_again(): void
    {
        $lead = $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $this->released(peopleNeeded: 2);
        Notification::fake();

        $this->at('2026-10-19 18:30');
        app(CoverLadder::class)->run();
        $this->at('2026-10-21 18:30');
        $this->assertSame(['resent' => 0, 'escalated' => 1], app(CoverLadder::class)->run());

        Notification::assertSentTo($lead, CoverUrgentNotification::class, function (CoverUrgentNotification $notification) use ($lead) {
            $lines = implode("\n", $notification->toMail($lead)->introLines);

            return str_contains($lines, 'Needs 2 more people.')
                && str_contains($lines, 'We emailed 3 volunteers when it was released and again 72 hours before.');
        });
    }

    public function test_shadows_hear_at_24_hours_only_when_nobody_but_shadows_is_left(): void
    {
        $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $stillCovered = $this->released(peopleNeeded: 2);
        $this->on($stillCovered, User::factory()->create(['name' => 'Sam Regular']));
        $coveredShadow = User::factory()->create(['name' => 'Kim Shadow']);
        $this->on($stillCovered, $coveredShadow, AssignmentType::Shadow);

        $this->travelTo(now()->addMinute());
        $empty = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, '2026-10-22', '15:00', '18:00', 1, 1, null);
        CoverRequest::create(['shift_id' => $empty->id, 'released_by' => $this->robin->id, 'notified_count' => 3]);
        $aloneShadow = User::factory()->create(['name' => 'Lee Shadow']);
        $this->on($empty, $aloneShadow, AssignmentType::Shadow);
        Notification::fake();

        $this->at('2026-10-21 18:30');
        $this->assertSame(['resent' => 0, 'escalated' => 2], app(CoverLadder::class)->run());

        Notification::assertNothingSentTo($coveredShadow);
        Notification::assertSentToTimes($aloneShadow, ShadowSpotNotification::class, 1);
        Notification::assertSentTo($aloneShadow, ShadowSpotNotification::class, function (ShadowSpotNotification $notification) use ($aloneShadow) {
            $mail = $notification->toMail($aloneShadow);
            $lines = implode("\n", $mail->introLines);

            return $notification->notice === ShadowSpotNotice::StillUncovered
                && $mail->subject === 'Your shadow shift tomorrow still has nobody to shadow'
                && str_contains($lines, 'Nobody has taken Robin\'s Front desk shift on Thursday, October 22 yet. If someone does, we\'ll email you.')
                && str_contains($lines, 'If nobody does, there\'s nobody to shadow, so please don\'t come in for it. Alex (Shift lead) knows.');
        });
    }

    public function test_the_ladder_skips_cancelled_shifts_and_resumes_when_brought_back(): void
    {
        $shift = $this->released();
        Notification::fake();

        app(CancelShift::class)->handle($this->coordinator, $shift, 'The building is closed.');
        $this->at('2026-10-19 18:30');

        $this->assertSame(['resent' => 0, 'escalated' => 0], app(CoverLadder::class)->run());
        Notification::assertNotSentTo($this->morgan, CoverRequestNotification::class);
        $this->assertNull(CoverRequest::query()->where('shift_id', $shift->id)->sole()->resent_at);

        app(RestoreShift::class)->handle($this->coordinator, $shift);

        $this->assertSame(['resent' => 1, 'escalated' => 0], app(CoverLadder::class)->run());
        Notification::assertSentTo($this->morgan, CoverRequestNotification::class, fn (CoverRequestNotification $notification) => $notification->toMail($this->morgan)->subject === 'Still open: Front desk on Thursday, October 22');

        app(CancelShift::class)->handle($this->coordinator, $shift, 'The building is closed after all.');
        $lead = $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $this->at('2026-10-21 18:30');

        $this->assertSame(['resent' => 0, 'escalated' => 0], app(CoverLadder::class)->run());
        Notification::assertNotSentTo($lead, CoverUrgentNotification::class);
    }

    public function test_a_shift_filled_another_way_gets_no_more_emails(): void
    {
        $lead = $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $shift = $this->released();
        Notification::fake();

        app(PutOnShift::class)->handle($this->coordinator, $shift, $this->morgan, AssignmentType::Regular);

        $this->at('2026-10-19 18:30');
        $this->assertSame(['resent' => 0, 'escalated' => 0], app(CoverLadder::class)->run());
        $this->at('2026-10-21 18:30');
        $this->assertSame(['resent' => 0, 'escalated' => 0], app(CoverLadder::class)->run());

        Notification::assertNotSentTo($this->coordinator, CoverRequestNotification::class);
        Notification::assertNotSentTo($lead, CoverUrgentNotification::class);
        // Putting Morgan on filled the cover request, and the ladder never touched it.
        $this->assertSame(0, ChangeLogEntry::query()->ofAction('cover_request.resent')->count());
        $this->assertSame(0, ChangeLogEntry::query()->ofAction('cover_request.escalated')->count());
        $this->assertSame(1, ChangeLogEntry::query()->ofAction('cover_request.filled')->count());
        $this->assertSame($this->morgan->id, CoverRequest::query()->where('shift_id', $shift->id)->sole()->filled_by);
    }

    public function test_no_resend_when_released_inside_72_hours(): void
    {
        $lead = $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $this->at('2026-10-20 12:00');
        $shift = $this->released();
        Notification::fake();

        $this->at('2026-10-20 12:10');
        $this->assertSame(['resent' => 0, 'escalated' => 0], app(CoverLadder::class)->run());
        Notification::assertNothingSent();
        $this->assertNull(CoverRequest::query()->where('shift_id', $shift->id)->sole()->resent_at);

        // It still escalates at 24 hours, and says it wasn't sent again.
        $this->at('2026-10-21 18:30');
        $this->assertSame(['resent' => 0, 'escalated' => 1], app(CoverLadder::class)->run());
        Notification::assertSentTo($lead, CoverUrgentNotification::class, fn (CoverUrgentNotification $notification) => str_contains(implode("\n", $notification->toMail($lead)->introLines), 'Robin released it on Tuesday, October 20. We emailed 3 volunteers when it was released.'));
    }

    public function test_a_second_release_inside_72_hours_gets_no_second_resend(): void
    {
        $shift = $this->released(peopleNeeded: 2);
        Notification::fake();

        $this->at('2026-10-19 18:30');
        $this->assertSame(['resent' => 1, 'escalated' => 0], app(CoverLadder::class)->run());

        // Sam releases the other place 50 hours before the start.
        $this->at('2026-10-20 16:30');
        $sam = User::factory()->create(['name' => 'Sam Example']);
        $second = CoverRequest::create(['shift_id' => $shift->id, 'released_by' => $sam->id, 'notified_count' => 2]);

        $this->at('2026-10-20 16:40');
        $this->assertSame(['resent' => 0, 'escalated' => 0], app(CoverLadder::class)->run());

        Notification::assertSentToTimes($this->morgan, CoverRequestNotification::class, 1);
        $this->assertNull($second->fresh()->resent_at);
        $this->assertSame(1, ChangeLogEntry::query()->forSubject($shift)->ofAction('cover_request.resent')->count());
    }

    public function test_several_open_requests_send_one_email_each_naming_the_oldest(): void
    {
        $shift = $this->released(peopleNeeded: 2);
        $this->travelTo(now()->addHour());
        $sam = User::factory()->create(['name' => 'Sam Example']);
        CoverRequest::create(['shift_id' => $shift->id, 'released_by' => $sam->id, 'note' => 'Sorry!', 'notified_count' => 2]);
        Notification::fake();

        $this->at('2026-10-19 18:30');
        $this->assertSame(['resent' => 1, 'escalated' => 0], app(CoverLadder::class)->run());

        Notification::assertSentToTimes($this->morgan, CoverRequestNotification::class, 1);
        Notification::assertSentTo($this->morgan, CoverRequestNotification::class, fn (CoverRequestNotification $notification) => str_contains(implode("\n", $notification->toMail($this->morgan)->introLines), 'Nobody has taken Robin\'s Front desk shift yet.'));
        $this->assertSame(2, ChangeLogEntry::query()->forSubject($shift)->ofAction('cover_request.resent')->count());
        $this->assertSame(0, CoverRequest::query()->whereNull('resent_at')->count());
    }

    public function test_the_command_runs_the_ladder_and_says_what_it_did(): void
    {
        $this->released();
        Notification::fake();
        $this->at('2026-10-19 18:30');

        $this->artisan('ssvs:cover-requests')
            ->expectsOutput('Sent cover requests again for 1 shift and marked 0 shifts urgent.')
            ->assertSuccessful();
    }
}
