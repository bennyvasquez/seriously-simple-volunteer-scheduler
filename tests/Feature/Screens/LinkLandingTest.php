<?php

namespace Tests\Feature\Screens;

use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\InviteVolunteer;
use App\Actions\Accounts\IssueLoginLink;
use App\Enums\LoginLinkPurpose;
use App\Enums\Role;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class LinkLandingTest extends TestCase
{
    use RefreshDatabase;

    private function withRole(Role $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        app(GrantRole::class)->handle(null, $user, $role);

        return $user;
    }

    private function invitationUrlFor(User $user): string
    {
        $url = null;
        Notification::assertSentTo($user, InvitationNotification::class, function (InvitationNotification $notification) use (&$url) {
            $url = $notification->url;

            return true;
        });

        return $url;
    }

    public function test_a_sign_in_link_shows_the_button_and_uses_nothing(): void
    {
        $user = User::factory()->withoutPassword()->create();
        $url = app(IssueLoginLink::class)->handle($user, LoginLinkPurpose::SignIn);

        $this->get($url)->assertOk()->assertSee('Log me in')->assertSee('Keep me logged in on this device')->assertDontSee('Welcome to');

        $this->assertNull($user->loginLinks()->sole()->used_at);
        $this->assertGuest();
    }

    public function test_an_invitation_names_who_invited_them(): void
    {
        Notification::fake();
        $alex = $this->withRole(Role::Coordinator, 'Alex');
        $user = app(InviteVolunteer::class)->handle($alex, 'Robin', 'robin@example.org');

        $this->get($this->invitationUrlFor($user))->assertOk()->assertSee('Welcome to')->assertSee('Alex invited you')->assertSee('Get started');

        $this->assertNull($user->loginLinks()->sole()->used_at);
    }

    public function test_an_expired_link_shows_the_expired_state_without_using_anything(): void
    {
        $user = User::factory()->withoutPassword()->create();
        $url = app(IssueLoginLink::class)->handle($user, LoginLinkPurpose::SignIn);
        $this->travel(16)->minutes();

        $this->get($url)->assertOk()
            ->assertSee('This link doesn\'t work anymore')
            ->assertSee('Email me a new link')
            ->assertSee('ask whoever invited you to send a new one')
            ->assertDontSee('Log me in');
    }

    public function test_an_unknown_link_shows_the_expired_state(): void
    {
        $this->get(route('login-link.show', 'nonsense'))->assertOk()->assertSee('This link doesn\'t work anymore');
    }

    public function test_a_used_link_shows_the_expired_state_and_uses_nothing(): void
    {
        $user = User::factory()->withoutPassword()->create();
        $url = app(IssueLoginLink::class)->handle($user, LoginLinkPurpose::SignIn);
        $usedAt = now()->subMinute()->startOfSecond();
        $user->loginLinks()->sole()->forceFill(['used_at' => $usedAt])->save();

        $this->get($url)->assertOk()->assertSee('This link doesn\'t work anymore')->assertDontSee('Log me in');

        $this->assertTrue($usedAt->equalTo($user->loginLinks()->sole()->used_at));
        $this->assertGuest();
    }

    public function test_a_deactivated_persons_link_shows_the_expired_state_and_uses_nothing(): void
    {
        $user = User::factory()->withoutPassword()->create();
        $url = app(IssueLoginLink::class)->handle($user, LoginLinkPurpose::SignIn);
        $user->forceFill(['deactivated_at' => now()])->save();

        $this->get($url)->assertOk()->assertSee('This link doesn\'t work anymore')->assertDontSee('Log me in');

        $this->assertNull($user->loginLinks()->sole()->used_at);
        $this->assertGuest();
    }

    public function test_an_expired_or_used_invitation_explains_invitations(): void
    {
        Notification::fake();
        $alex = $this->withRole(Role::Coordinator, 'Alex');
        $user = app(InviteVolunteer::class)->handle($alex, 'Robin', 'robin@example.org');
        $url = $this->invitationUrlFor($user);
        $this->travel(8)->days();

        $this->get($url)->assertOk()
            ->assertSee('This invitation doesn\'t work anymore')
            ->assertSee('Invitations work once, for 7 days. If you already used it, log in with your email address instead. If it ran out, ask whoever invited you to send a new one.')
            ->assertSee('Go to log in')
            ->assertDontSee('Login links work once');

        $this->travelBack();
        $user->loginLinks()->sole()->forceFill(['used_at' => now(), 'expires_at' => now()->addDay()])->save();
        $this->get($url)->assertOk()->assertSee('This invitation doesn\'t work anymore');
    }

    public function test_remember_me_on_the_landing_page_is_honored(): void
    {
        $user = User::factory()->withoutPassword()->create();
        $url = app(IssueLoginLink::class)->handle($user, LoginLinkPurpose::SignIn);

        $response = $this->post(route('login-link.consume', basename(parse_url($url, PHP_URL_PATH))), ['remember' => '1']);

        $response->assertCookie(Auth::guard()->getRecallerName());
    }

    public function test_without_remember_me_no_long_lived_cookie_is_set(): void
    {
        $user = User::factory()->withoutPassword()->create();
        $url = app(IssueLoginLink::class)->handle($user, LoginLinkPurpose::SignIn);

        $response = $this->post(route('login-link.consume', basename(parse_url($url, PHP_URL_PATH))));

        $response->assertCookieMissing(Auth::guard()->getRecallerName());
    }
}
