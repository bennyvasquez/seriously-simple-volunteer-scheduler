<?php

namespace Tests\Feature\Accounts;

use App\Actions\Accounts\CreatePerson;
use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\InvitePerson;
use App\Enums\Role;
use App\Models\ChangeLogEntry;
use App\Models\User;
use App\Notifications\InvitationNotification;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InvitePersonTest extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutDefer();
        Notification::fake();
        $this->coordinator = User::factory()->create(['name' => 'Alex']);
        app(GrantRole::class)->handle(null, $this->coordinator, Role::Coordinator);
    }

    private function invite(string $email, ?User $inviter = null): User
    {
        $inviter ??= $this->coordinator;
        $person = app(CreatePerson::class)->handle($inviter, 'Robin', $email);
        app(InvitePerson::class)->handle($inviter, $person);

        return $person;
    }

    public function test_a_coordinator_can_invite_a_volunteer(): void
    {
        $user = $this->invite('Robin@Example.org');

        $this->assertSame('robin@example.org', $user->email);
        $this->assertNull($user->fresh()->password);
        $this->assertNull($user->fresh()->email_verified_at);
        Notification::assertSentTo($user, InvitationNotification::class);
        $this->assertTrue(ChangeLogEntry::query()->where('action', 'user.invited')->where('actor_id', $this->coordinator->id)->exists());
        $this->assertArrayNotHasKey('password', ChangeLogEntry::query()->where('action', 'user.created')->sole()->changes);
    }

    public function test_the_invitation_greets_by_first_name_and_names_the_inviter_by_short_name(): void
    {
        $inviter = User::factory()->create(['name' => 'Jordan Pell']);
        app(GrantRole::class)->handle(null, $inviter, Role::Coordinator);
        $person = app(CreatePerson::class)->handle($inviter, 'Sam Rivera', 'sam@example.org');
        app(InvitePerson::class)->handle($inviter, $person);

        Notification::assertSentTo($person, InvitationNotification::class, function (InvitationNotification $notification) use ($person) {
            $mail = $notification->toMail($person);

            $this->assertSame('Hi Sam,', $mail->greeting);
            $this->assertStringContainsString('Jordan P. added you to', $mail->introLines[0]);
            $this->assertStringNotContainsString('Pell', $mail->introLines[0]);
            $this->assertStringNotContainsString('Rivera', $mail->greeting);

            return true;
        });
    }

    public function test_the_invitation_link_logs_the_volunteer_in(): void
    {
        $user = $this->invite('robin@example.org');

        $url = null;
        Notification::assertSentTo($user, InvitationNotification::class, function (InvitationNotification $notification) use (&$url) {
            $url = $notification->url;

            return true;
        });

        $this->post(route('login-link.consume', basename(parse_url($url, PHP_URL_PATH))));

        $this->assertAuthenticatedAs($user);
    }

    public function test_an_address_can_only_have_one_account(): void
    {
        User::factory()->create(['email' => 'robin@example.org']);

        $this->expectException(DomainException::class);

        $this->invite('ROBIN@example.org');
    }

    public function test_a_name_with_control_characters_is_refused(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('This name has characters we can\'t use.');

        app(CreatePerson::class)->handle(null, "Robin \e[2K Example", 'robin@example.org');
    }

    public function test_a_volunteer_cannot_invite_people(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->invite('robin@example.org', User::factory()->create());
    }

    public function test_the_invitation_logs_the_new_name_and_address(): void
    {
        $user = $this->invite('robin@example.org');

        $entry = ChangeLogEntry::query()->where('action', 'user.created')->sole();
        $this->assertSame('robin@example.org', $entry->changes['email']['new']);
        $this->assertSame($user->id, $entry->affected_user_id);
    }
}
