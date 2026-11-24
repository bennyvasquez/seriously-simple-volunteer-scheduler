<?php

namespace Tests\Feature\Scheduling;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\CancelShift;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\ReleaseShifts;
use App\Actions\Scheduling\TakeShadowSpot;
use App\ChangeLog\Describe;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Http\Controllers\Volunteer\HomeController;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\ShadowSignedUpLeadNotification;
use App\Notifications\ShadowSignedUpNotification;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class TakeShadowSpotTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private Area $frontDesk;

    private Area $hostedEvents;

    private User $coordinator;

    private User $lead;

    private User $sam;

    private User $avery;

    private User $quinn;

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
        $this->sam = User::factory()->cleared()->create(['name' => 'Sam Example']);
        $this->avery = User::factory()->create(['name' => 'Avery Trainee']);
        $this->quinn = User::factory()->create(['name' => 'Quinn Trainee']);
    }

    private function withRole(string $name, Role $role, ?Area $area = null): User
    {
        $user = User::factory()->create(['name' => $name]);
        app(GrantRole::class)->handle(null, $user, $role, $area);
        $this->enableTwoFactor($user);

        return $user;
    }

    /** A 6:30 to 9:30pm one-time shift with Sam on it. */
    private function shift(string $date = '2026-10-22', ?Area $area = null, int $shadowSpots = 1, string $from = '18:30', string $to = '21:30', bool $withSam = true): Shift
    {
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator, $area ?? $this->frontDesk, $date, $from, $to, 1, $shadowSpots, null);

        if ($withSam) {
            $this->on($shift, $this->sam);
        }

        return $shift->fresh();
    }

    private function on(Shift $shift, User $person, AssignmentType $type = AssignmentType::Regular): void
    {
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $person->id, 'type' => $type]);
    }

    private function text(MailMessage $mail): string
    {
        return implode("\n", array_merge($mail->introLines, $mail->outroLines));
    }

    private function assertRefused(string $message, User $person, Shift $shift): void
    {
        try {
            app(TakeShadowSpot::class)->handle($person, $shift);
            $this->fail('Signing up should be refused.');
        } catch (DomainException $e) {
            $this->assertSame($message, $e->getMessage());
        }

        $this->assertFalse(ChangeLogEntry::query()->ofAction('assignment.added')->where('details->via', 'shadow_signup')->exists());
    }

    public function test_signing_up_logs_and_emails(): void
    {
        $otherLead = $this->withRole('Blake Lead', Role::AreaLead, $this->hostedEvents);
        $shift = $this->shift();
        Notification::fake();

        $assignment = app(TakeShadowSpot::class)->handle($this->avery, $shift);

        $this->assertSame($this->avery->id, $assignment->user_id);
        $this->assertSame(AssignmentType::Shadow, $assignment->fresh()->type);

        $added = ChangeLogEntry::query()->ofAction('assignment.added')->about($this->avery)->sole();
        $this->assertSame($this->avery->id, $added->actor_id);
        $this->assertTrue($added->subject->is($shift));
        $this->assertSame(['assignment_id' => $assignment->id, 'type' => 'shadow', 'via' => 'shadow_signup'], $added->details);
        $this->assertSame(['old' => null, 'new' => 'shadow'], $added->changes['type']);
        $this->assertSame('Signed up to shadow the Front desk shift on Thursday, October 22, 6:30 to 9:30pm', Describe::entry($added, $this->coordinator));

        Notification::assertSentToTimes($this->avery, ShadowSignedUpNotification::class, 1);
        Notification::assertSentTo($this->avery, ShadowSignedUpNotification::class, function (ShadowSignedUpNotification $notification) {
            $mail = $notification->toMail($this->avery);

            return $mail->subject === 'You\'re shadowing Front desk on Thursday, October 22'
                && str_contains($this->text($mail), 'You\'re signed up to shadow Front desk on Thursday, October 22, 6:30 to 9:30pm.')
                && str_contains($this->text($mail), 'You\'ll shadow Sam E.')
                && str_contains($this->text($mail), 'If you can\'t make it, press Can\'t make it on Home so a coordinator knows.');
        });
        Notification::assertSentToTimes($this->lead, ShadowSignedUpLeadNotification::class, 1);
        Notification::assertSentTo($this->lead, ShadowSignedUpLeadNotification::class, function (ShadowSignedUpLeadNotification $notification) {
            $mail = $notification->toMail($this->lead);

            return $mail->subject === 'Avery T. is shadowing Front desk, Thursday, October 22'
                && str_contains($this->text($mail), 'Avery T. signed up to shadow Front desk on Thursday, October 22, 6:30 to 9:30pm.')
                && str_contains($this->text($mail), 'Sam E. is on that shift.');
        });
        Notification::assertNothingSentTo($otherLead);
        Notification::assertNothingSentTo($this->sam);
        Notification::assertNothingSentTo($this->coordinator);
    }

    public function test_leads_who_cant_sign_in_arent_emailed(): void
    {
        $offLead = $this->withRole('Drew Lead', Role::AreaLead, $this->frontDesk);
        User::query()->whereKey($offLead->id)->update(['deactivated_at' => now()]);
        $shift = $this->shift();
        Notification::fake();

        app(TakeShadowSpot::class)->handle($this->avery, $shift);

        Notification::assertNothingSentTo($offLead);
        Notification::assertSentTo($this->lead, ShadowSignedUpLeadNotification::class);
    }

    public function test_a_cancelled_shift_is_refused(): void
    {
        $shift = $this->shift();
        app(CancelShift::class)->handle($this->coordinator, $shift, 'Closed');

        $this->assertRefused('This shift was cancelled.', $this->avery, $shift->fresh());
    }

    public function test_a_shift_that_has_started_is_refused(): void
    {
        $shift = Shift::create(['area_id' => $this->frontDesk->id, 'local_date' => '2026-10-14', 'starts_at' => '2026-10-14 12:00:00', 'ends_at' => '2026-10-14 16:00:00', 'people_needed' => 1, 'shadow_spots' => 1]);
        $this->on($shift, $this->sam);

        $this->assertRefused('This shift has already started.', $this->avery, $shift);
    }

    public function test_someone_turned_off_is_refused(): void
    {
        $shift = $this->shift();
        $stale = User::query()->findOrFail($this->avery->id);
        User::query()->whereKey($this->avery->id)->update(['deactivated_at' => now()]);

        $this->assertRefused('Your account has been turned off, so you can\'t take shifts.', $stale, $shift);
    }

    public function test_someone_already_on_it_is_refused(): void
    {
        $shift = $this->shift();
        $this->on($shift, $this->avery);

        $this->assertRefused('You\'re already on this shift.', $this->avery, $shift);
    }

    public function test_someone_already_shadowing_it_is_refused(): void
    {
        $shift = $this->shift();
        $this->on($shift, $this->avery, AssignmentType::Shadow);

        $this->assertRefused('You\'re shadowing this shift.', $this->avery, $shift);
    }

    public function test_someone_cleared_is_refused(): void
    {
        $robin = User::factory()->cleared()->create(['name' => 'Robin Example']);

        $this->assertRefused('Shadow spots are for people who aren\'t cleared yet. You can take shifts instead.', $robin, $this->shift());
    }

    public function test_a_shift_with_no_shadow_spot_left_is_refused(): void
    {
        $shift = $this->shift();
        $this->on($shift, $this->quinn, AssignmentType::Shadow);

        $this->assertRefused('There\'s no shadow spot left on this shift.', $this->avery, $shift);
        $this->assertRefused('There\'s no shadow spot left on this shift.', $this->avery, $this->shift('2026-10-23', shadowSpots: 0));
    }

    public function test_an_overlapping_shift_is_refused(): void
    {
        $shift = $this->shift();
        $other = $this->shift(area: $this->hostedEvents, from: '19:00', to: '20:00');
        $this->on($other, $this->avery, AssignmentType::Shadow);

        $this->assertRefused('You\'re already on Hosted events, Thursday, October 22, 7 to 8pm.', $this->avery, $shift);
    }

    public function test_a_shift_with_nobody_to_shadow_isnt_offered(): void
    {
        // Nobody was ever on it.
        $empty = $this->shift(withSam: false);
        // Sam was on it and released it, so only a cover request is left.
        $released = $this->shift('2026-10-23');
        app(ReleaseShifts::class)->one($this->sam, $released, null);
        // Only another shadow on it: still nobody to learn from.
        $onlyShadows = $this->shift('2026-10-24', shadowSpots: 2, withSam: false);
        $this->on($onlyShadows, $this->quinn, AssignmentType::Shadow);
        $offered = $this->shift('2026-10-25');

        $this->assertSame([$offered->id], app(HomeController::class)->openShadowSpots($this->avery)->pluck('id')->all());

        foreach ([$empty, $released->fresh(), $onlyShadows] as $shift) {
            $this->assertRefused('Nobody is on this shift to shadow yet.', $this->avery, $shift);
        }
    }

    public function test_two_people_taking_the_last_shadow_spot_only_one_gets_it(): void
    {
        $shift = $this->shift();
        // Loaded with its assignments before Avery signs up, the way Quinn's request would have read it, so a check on the passed model would see the spot still open. Only the locked re-read can refuse Quinn.
        $stale = Shift::query()->with('assignments')->findOrFail($shift->id);
        Notification::fake();

        app(TakeShadowSpot::class)->handle($this->avery, $shift);

        try {
            app(TakeShadowSpot::class)->handle($this->quinn, $stale);
            $this->fail('The second person should be refused.');
        } catch (DomainException $e) {
            $this->assertSame('There\'s no shadow spot left on this shift.', $e->getMessage());
        }

        $this->assertSame([$this->avery->id], $shift->assignments()->where('type', AssignmentType::Shadow)->pluck('user_id')->all());
        $this->assertSame(1, ChangeLogEntry::query()->ofAction('assignment.added')->where('details->via', 'shadow_signup')->count());
        Notification::assertSentToTimes($this->lead, ShadowSignedUpLeadNotification::class, 1);
        Notification::assertNothingSentTo($this->quinn);
    }

    public function test_open_shadow_spots_list_whats_free_and_who_youd_shadow(): void
    {
        $jordan = User::factory()->cleared()->create(['name' => 'Jordan Example']);
        $twoOnIt = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, '2026-10-16', '10:00', '12:00', 2, 1, null);
        $this->on($twoOnIt, $this->sam);
        $this->on($twoOnIt, $jordan);
        $later = $this->shift('2026-10-22');
        $mine = $this->shift('2026-10-19', area: $this->hostedEvents);
        $this->on($mine, $this->avery, AssignmentType::Shadow);
        $overlapping = $this->shift('2026-10-19', from: '19:00', to: '20:00');
        $full = $this->shift('2026-10-20');
        $this->on($full, $this->quinn, AssignmentType::Shadow);
        $cancelled = $this->shift('2026-10-21');
        app(CancelShift::class)->handle($this->coordinator, $cancelled, 'Closed');
        $beyond = $this->shift('2026-11-30');

        $open = app(HomeController::class)->openShadowSpots($this->avery);

        $this->assertSame([$twoOnIt->id, $later->id], $open->pluck('id')->all());
        $this->assertSame(['Sam E. and Jordan E.', 'Sam E.'], $open->pluck('shadowing_with')->all());
    }
}
