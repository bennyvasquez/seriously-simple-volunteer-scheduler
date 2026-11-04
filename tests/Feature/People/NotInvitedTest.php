<?php

namespace Tests\Feature\People;

use App\Actions\Accounts\CreatePerson;
use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\InvitePerson;
use App\Actions\Scheduling\CancelShift;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\PutOnShift;
use App\Enums\AssignmentType;
use App\Enums\InvitationStatus;
use App\Enums\Role;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\LoginLink;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class NotInvitedTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        // Emails are deferred until after the response; calling an action directly has no response, so run them right away.
        $this->withoutDefer();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', 'America/Detroit'));
        Organization::factory()->create(['timezone' => 'America/Detroit']);
        $this->coordinator = User::factory()->create(['name' => 'Alex']);
        app(GrantRole::class)->handle(null, $this->coordinator, Role::Coordinator);
    }

    /** Messages the real (array) mailer was asked to send. */
    private function sentMessages(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()->all();
    }

    private function lastInvitationUrl(): string
    {
        $messages = $this->sentMessages();
        $body = quoted_printable_decode(end($messages)->getOriginalMessage()->getBody()->bodyToString());
        preg_match('#http[^\s"<]*/login-link/[A-Za-z0-9]+#', $body, $matches);

        return $matches[0];
    }

    public function test_a_person_who_is_not_invited_gets_no_email_and_cannot_sign_in(): void
    {
        $person = User::factory()->notInvited()->create(['email' => 'robin@example.org', 'password' => 'a long password']);

        $this->post(route('login-link.request'), ['email' => 'robin@example.org'])
            ->assertSessionHas('status', 'If that address has an account, we\'ve emailed a link. It works for 15 minutes.');
        $this->post(route('password.email'), ['email' => 'robin@example.org']);
        $this->post(route('login'), ['email' => 'robin@example.org', 'password' => 'a long password']);

        $this->assertGuest();
        // Laravel's password broker writes a reset token before it asks the user to send the email, the same as for a turned-off account today; what matters is that nothing is sent.
        $this->assertSame(0, $person->loginLinks()->count());
        $this->assertCount(0, $this->sentMessages());
    }

    public function test_schedule_emails_skip_people_who_are_not_invited(): void
    {
        $person = User::factory()->notInvited()->create(['email' => 'robin@example.org']);
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator, Area::factory()->create(), '2026-11-26', '15:30', '18:30', 2, 0, null);
        $other = app(CreateOneTimeShift::class)->handle($this->coordinator, $shift->area, '2026-11-27', '15:30', '18:30', 2, 0, null);

        app(PutOnShift::class)->handle($this->coordinator, $shift, $person, AssignmentType::Regular);
        $this->assertCount(0, $this->sentMessages());

        app(InvitePerson::class)->handle($this->coordinator, $person);
        app(PutOnShift::class)->handle($this->coordinator, $other, $person, AssignmentType::Regular);

        $this->assertCount(2, $this->sentMessages());
    }

    public function test_a_turned_off_or_removed_person_gets_no_invitation_email(): void
    {
        $off = User::factory()->notInvited()->deactivated()->create();
        $removed = User::factory()->notInvited()->create(['is_removed_person' => true]);

        $this->assertFalse($off->canSignIn());
        $this->assertNull($off->routeNotificationForMail(new InvitationNotification('https://example.org')));
        $this->assertNull($removed->routeNotificationForMail(new InvitationNotification('https://example.org')));
    }

    public function test_creating_a_person_sends_nothing_and_logs_it(): void
    {
        $person = app(CreatePerson::class)->handle($this->coordinator, 'Robin Example', 'Robin@Example.org', 'abc123');

        $this->assertSame('robin@example.org', $person->email);
        $this->assertSame(InvitationStatus::NotInvited, $person->invitationStatus());
        $this->assertSame('Not invited yet', $person->invitationStatus()->label());
        $this->assertCount(0, $this->sentMessages());
        $entry = ChangeLogEntry::query()->ofAction('user.created')->sole();
        $this->assertSame($this->coordinator->id, $entry->actor_id);
        $this->assertSame(['old' => null, 'new' => 'abc123'], $entry->changes['member_id']);
        $this->assertArrayNotHasKey('password', $entry->changes);
    }

    public function test_creating_a_person_needs_a_coordinator_and_a_new_address(): void
    {
        try {
            app(CreatePerson::class)->handle(User::factory()->create(), 'Robin', 'robin@example.org');
            $this->fail('A volunteer created a person.');
        } catch (AuthorizationException) {
            $this->assertSame(0, User::query()->where('email', 'robin@example.org')->count());
        }

        app(CreatePerson::class)->handle($this->coordinator, 'Robin', 'robin@example.org');
        $this->expectException(DomainException::class);
        app(CreatePerson::class)->handle($this->coordinator, 'Other Robin', 'ROBIN@example.org');
    }

    public function test_inviting_and_resending(): void
    {
        $person = app(CreatePerson::class)->handle($this->coordinator, 'Robin Example', 'robin@example.org');

        app(InvitePerson::class)->handle($this->coordinator, $person);

        $person->refresh();
        $this->assertSame(InvitationStatus::Waiting, $person->invitationStatus());
        $this->assertNotNull($person->invited_at);
        $this->assertSame(1, ChangeLogEntry::query()->ofAction('user.invited')->count());
        $this->assertCount(1, $this->sentMessages());
        $firstUrl = $this->lastInvitationUrl();

        app(InvitePerson::class)->handle($this->coordinator, $person);

        $this->assertSame(InvitationStatus::Waiting, $person->fresh()->invitationStatus());
        $this->assertSame(1, ChangeLogEntry::query()->ofAction('user.invited')->count());
        $this->assertSame(1, ChangeLogEntry::query()->ofAction('user.invitation_resent')->count());
        $this->assertCount(2, $this->sentMessages());
        $this->post(route('login-link.consume', basename($firstUrl)));
        $this->assertGuest();
        $this->assertNull($person->fresh()->email_verified_at);

        $secondUrl = $this->lastInvitationUrl();

        $this->travel(8)->days();
        $this->assertSame(InvitationStatus::RanOut, $person->fresh()->invitationStatus());
        $this->assertSame(1, User::query()->withInvitationStatus(InvitationStatus::RanOut)->count());
        $this->travelBack();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', 'America/Detroit'));

        $this->post(route('login-link.consume', basename($secondUrl)));
        $this->assertAuthenticatedAs($person);
        $this->assertSame(InvitationStatus::Accepted, $person->fresh()->invitationStatus());
        $this->assertSame(1, User::query()->withInvitationStatus(InvitationStatus::Accepted)->where('email', 'robin@example.org')->count());

        $this->expectException(DomainException::class);
        app(InvitePerson::class)->handle($this->coordinator, $person->fresh());
    }

    public function test_the_status_scopes_match_the_status_method(): void
    {
        $notInvited = User::factory()->notInvited()->create();
        $accepted = User::factory()->create();
        $waiting = app(CreatePerson::class)->handle($this->coordinator, 'Waiting', 'waiting@example.org');
        app(InvitePerson::class)->handle($this->coordinator, $waiting);

        foreach (InvitationStatus::cases() as $status) {
            $expected = User::query()->get()->filter(fn (User $user) => $user->invitationStatus() === $status)->pluck('id')->sort()->values()->all();
            $actual = User::query()->withInvitationStatus($status)->pluck('id')->sort()->values()->all();
            $this->assertSame($expected, $actual, $status->name);
        }

        $this->assertSame([$notInvited->id], User::query()->withInvitationStatus(InvitationStatus::NotInvited)->pluck('id')->all());
    }

    public function test_only_coordinators_can_invite_and_nobody_is_invited_twice_after_removal_or_turn_off(): void
    {
        $person = User::factory()->notInvited()->create();

        try {
            app(InvitePerson::class)->handle(User::factory()->create(), $person);
            $this->fail('A volunteer invited a person.');
        } catch (AuthorizationException) {
            $this->assertNull($person->fresh()->invited_at);
        }

        $person->forceFill(['deactivated_at' => now()])->save();
        $this->expectException(DomainException::class);
        app(InvitePerson::class)->handle($this->coordinator, $person);
    }

    public function test_the_removed_person_is_left_out_of_people(): void
    {
        $removed = User::factory()->create(['is_removed_person' => true]);

        $this->assertFalse($removed->canSignIn());
        $this->assertFalse(User::query()->people()->whereKey($removed->id)->exists());
        $this->assertFalse($this->coordinator->visiblePeople()->whereKey($removed->id)->exists());
    }

    public function test_existing_accounts_count_as_invited_after_the_migration(): void
    {
        $migration = require database_path('migrations/'.collect(scandir(database_path('migrations')))->first(fn ($file) => str_ends_with($file, '_add_people_columns.php')));

        DB::table('users')->insert(['name' => 'Old Account', 'email' => 'old@example.org', 'created_at' => '2026-01-05 10:00:00', 'updated_at' => '2026-01-05 10:00:00']);

        $migration::backfillInvitedAt();

        $this->assertSame('2026-01-05 10:00:00', DB::table('users')->where('email', 'old@example.org')->value('invited_at'));
    }

    public function test_cancelling_a_shift_counts_and_logs_only_people_who_can_be_emailed(): void
    {
        $this->enableTwoFactor($this->coordinator);
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator, Area::factory()->create(), '2026-11-26', '15:30', '18:30', 2, 0, null);
        $notInvited = User::factory()->notInvited()->create();
        $invited = User::factory()->create();
        app(PutOnShift::class)->handle($this->coordinator, $shift, $notInvited, AssignmentType::Regular);
        app(PutOnShift::class)->handle($this->coordinator, $shift, $invited, AssignmentType::Regular);
        $before = count($this->sentMessages());

        $this->actingAs($this->coordinator)->get(route('calendar.shift', [$shift, 'cancel' => 1]))->assertOk()->assertSee('1 person will be emailed this reason.');

        $emailed = app(CancelShift::class)->handle($this->coordinator, $shift, 'Power outage');

        $this->assertSame(1, $emailed);
        $entry = ChangeLogEntry::query()->forSubject($shift)->ofAction('shift.cancelled')->sole();
        $this->assertSame([$invited->id], $entry->details['people']);
        $this->assertCount($before + 1, $this->sentMessages());
    }

    public function test_a_link_held_by_a_person_who_is_not_invited_does_not_sign_them_in(): void
    {
        $person = User::factory()->notInvited()->withoutPassword()->create();
        $person->loginLinks()->create(['purpose' => 'sign_in', 'token_hash' => hash('sha256', 'held-token'), 'expires_at' => now()->addMinutes(10)]);

        $this->post(route('login-link.consume', 'held-token'));

        $this->assertGuest();
        $this->assertNull(LoginLink::query()->sole()->used_at);
    }

    public function test_a_refused_invitation_logs_nothing_and_sends_nothing(): void
    {
        $removed = User::factory()->notInvited()->create(['is_removed_person' => true]);
        $off = User::factory()->notInvited()->deactivated()->create();
        $accepted = User::factory()->create();
        $logged = ChangeLogEntry::query()->count();

        foreach ([$removed, $off, $accepted] as $person) {
            try {
                app(InvitePerson::class)->handle($this->coordinator, $person);
                $this->fail('A refused invitation went through.');
            } catch (DomainException) {
                // Expected.
            }
        }

        $this->assertNull($removed->fresh()->invited_at);
        $this->assertNull($off->fresh()->invited_at);
        $this->assertSame($logged, ChangeLogEntry::query()->count());
        $this->assertSame(0, LoginLink::query()->count());
        $this->assertCount(0, $this->sentMessages());
    }
}
