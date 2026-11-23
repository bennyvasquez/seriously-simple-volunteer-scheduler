<?php

namespace Tests\Feature\Accounts;

use App\Actions\Accounts\IssueLoginLink;
use App\Enums\LoginLinkPurpose;
use App\Models\ChangeLogEntry;
use App\Models\LoginLink;
use App\Models\User;
use App\Notifications\LoginLinkNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class LoginLinkTest extends TestCase
{
    use InteractsWithTwoFactor;
    use RefreshDatabase;

    private const ANSWER = 'If that address has an account, we\'ve emailed a link. It works for 15 minutes.';

    private function tokenFor(User $user, LoginLinkPurpose $purpose = LoginLinkPurpose::SignIn): string
    {
        $url = app(IssueLoginLink::class)->handle($user, $purpose);

        return basename(parse_url($url, PHP_URL_PATH));
    }

    private function useLink(string $token)
    {
        return $this->post(route('login-link.consume', $token));
    }

    public function test_asking_for_a_link_emails_one(): void
    {
        Notification::fake();
        $user = User::factory()->withoutPassword()->create(['email' => 'volunteer@example.org']);

        $this->post(route('login-link.request'), ['email' => 'Volunteer@Example.org'])
            ->assertSessionHas('status', self::ANSWER);

        Notification::assertSentTo($user, LoginLinkNotification::class);
    }

    public function test_a_failure_sending_the_email_does_not_change_the_answer(): void
    {
        User::factory()->create(['email' => 'volunteer@example.org']);
        Event::listen(NotificationSending::class, fn () => throw new \RuntimeException('The mail server is down.'));
        $this->withoutExceptionHandling();

        $this->post(route('login-link.request'), ['email' => 'volunteer@example.org'])
            ->assertRedirect()
            ->assertSessionHas('status', self::ANSWER)
            ->assertSessionHasNoErrors();
    }

    public function test_unknown_and_deactivated_addresses_get_the_same_answer(): void
    {
        Notification::fake();
        User::factory()->deactivated()->create(['email' => 'gone@example.org']);

        $this->post(route('login-link.request'), ['email' => 'nobody@example.org'])
            ->assertSessionHas('status', self::ANSWER);
        $this->post(route('login-link.request'), ['email' => 'gone@example.org'])
            ->assertSessionHas('status', self::ANSWER);

        Notification::assertNothingSent();
    }

    public function test_a_link_logs_you_in(): void
    {
        $user = User::factory()->withoutPassword()->create();

        $this->useLink($this->tokenFor($user))->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_opening_a_link_does_not_use_it_up(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        // Email security scanners open links with GET; that must not log anyone in or burn the link.
        $this->get(route('login-link.show', $token))->assertOk();
        $this->assertGuest();

        $this->useLink($token);
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_link_works_only_once(): void
    {
        $token = $this->tokenFor(User::factory()->create());
        $this->useLink($token);
        Auth::logout();

        $this->useLink($token)
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_sign_in_link_expires_after_15_minutes(): void
    {
        $token = $this->tokenFor(User::factory()->create());

        $this->travel(16)->minutes();

        $this->useLink($token)->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_an_invitation_lasts_7_days(): void
    {
        $user = User::factory()->withoutPassword()->create();
        $token = $this->tokenFor($user, LoginLinkPurpose::Invitation);

        $this->travel(6)->days();

        $this->useLink($token);
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_new_link_cancels_the_previous_one(): void
    {
        $user = User::factory()->create();
        $first = $this->tokenFor($user);
        $this->tokenFor($user);

        $this->useLink($first)->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_deactivated_person_cannot_use_a_link(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);
        $user->forceFill(['deactivated_at' => now()])->save();

        $this->useLink($token)->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_accepting_an_invitation_confirms_the_address_and_is_logged(): void
    {
        $user = User::factory()->withoutPassword()->create(['email_verified_at' => null]);

        $this->useLink($this->tokenFor($user, LoginLinkPurpose::Invitation));

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertTrue(ChangeLogEntry::query()->where('action', 'user.accepted_invitation')->exists());
    }

    public function test_someone_with_two_factor_still_needs_their_code(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->useLink($this->tokenFor($user))->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
        $this->assertSame('link', session('login.via'));
        $this->assertSame($user->id, session('login.id'));
    }

    public function test_asking_for_links_is_throttled(): void
    {
        User::factory()->create(['email' => 'volunteer@example.org']);

        foreach (range(1, 3) as $attempt) {
            $this->post(route('login-link.request'), ['email' => 'volunteer@example.org']);
        }

        $this->post(route('login-link.request'), ['email' => 'volunteer@example.org'])->assertStatus(429);
    }

    public function test_accepting_an_invitation_logs_the_confirmed_address(): void
    {
        $user = User::factory()->withoutPassword()->create(['email_verified_at' => null]);

        $this->useLink($this->tokenFor($user, LoginLinkPurpose::Invitation));

        $entry = ChangeLogEntry::query()->where('action', 'user.accepted_invitation')->sole();
        $this->assertNull($entry->changes['email_verified_at']['old']);
        $this->assertNotNull($entry->changes['email_verified_at']['new']);
    }

    private function tokenFromSentLink(User $user): string
    {
        $url = null;
        Notification::assertSentTo($user, LoginLinkNotification::class, function (LoginLinkNotification $notification) use (&$url) {
            $url = $notification->url;

            return true;
        });

        return basename(parse_url($url, PHP_URL_PATH));
    }

    public function test_the_destination_survives_a_different_browser(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'volunteer@example.org']);

        $this->get('/account')->assertRedirect('/login');
        $this->post(route('login-link.request'), ['email' => 'volunteer@example.org']);
        $token = $this->tokenFromSentLink($user);

        $this->flushSession();
        $this->useLink($token)->assertRedirect('/account');
    }

    public function test_an_outside_destination_is_ignored(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'volunteer@example.org']);

        $this->withSession(['url.intended' => 'https://example.org/steal'])
            ->post(route('login-link.request'), ['email' => 'volunteer@example.org']);
        $token = $this->tokenFromSentLink($user);

        $this->assertNull(LoginLink::query()->sole()->destination);

        $this->flushSession();
        $this->useLink($token)->assertRedirect(config('fortify.home'));
    }

    public function test_only_this_apps_own_paths_are_kept_as_a_destination(): void
    {
        $host = parse_url(config('app.url'), PHP_URL_HOST);

        $this->assertSame('/account', LoginLink::safeDestination(url('/account')));
        $this->assertSame('/account?tab=1', LoginLink::safeDestination(url('/account?tab=1')));
        $this->assertSame('/account', LoginLink::safeDestination('/account'));
        $this->assertNull(LoginLink::safeDestination(null));
        $this->assertNull(LoginLink::safeDestination('https://example.org/steal'));
        $this->assertNull(LoginLink::safeDestination('//example.org/steal'));
        $this->assertNull(LoginLink::safeDestination('https://'.$host.'.example.org/account'));
        $this->assertNull(LoginLink::safeDestination('account'));
        $this->assertNull(LoginLink::safeDestination(url('/'.str_repeat('a', 500))));
    }

    public function test_the_login_page_says_why_when_taking_a_shift(): void
    {
        $this->withSession(['url.intended' => url('/shifts/5/take')])
            ->get('/login')
            ->assertOk()
            ->assertSee('Log in to take this shift. Once you\'re in, we\'ll take you straight back to it.', false);

        $this->withSession(['url.intended' => url('/account')])
            ->get('/login')
            ->assertOk()
            ->assertDontSee('Log in to take this shift.', false);
    }

    public function test_a_two_factor_login_keeps_the_destination(): void
    {
        $user = User::factory()->create();
        $secret = $this->enableTwoFactor($user);
        $url = app(IssueLoginLink::class)->handle($user, LoginLinkPurpose::SignIn, destination: '/account');
        $token = basename(parse_url($url, PHP_URL_PATH));

        $this->useLink($token)->assertRedirect(route('two-factor.login'));
        $this->post(route('two-factor.login.store'), ['code' => $this->currentCode($secret)])->assertRedirect('/account');

        $this->assertAuthenticatedAs($user);
    }
}
