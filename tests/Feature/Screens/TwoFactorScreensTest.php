<?php

namespace Tests\Feature\Screens;

use App\Actions\Accounts\IssueLoginLink;
use App\Enums\LoginLinkPurpose;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class TwoFactorScreensTest extends TestCase
{
    use InteractsWithTwoFactor;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function leadWithTwoFactor(array $attributes = []): User
    {
        $lead = User::factory()->create(['email' => 'lead@example.org', 'password' => Hash::make('correct horse battery')] + $attributes);
        $this->enableTwoFactor($lead);

        return $lead;
    }

    private function signInTokenFor(User $user): string
    {
        return basename(parse_url(app(IssueLoginLink::class)->handle($user, LoginLinkPurpose::SignIn), PHP_URL_PATH));
    }

    public function test_after_a_password_login_the_email_option_is_offered(): void
    {
        $lead = $this->leadWithTwoFactor();
        $this->post('/login', ['email' => $lead->email, 'password' => 'correct horse battery']);

        $this->get(route('two-factor.login'))->assertOk()->assertSee('Enter your code')->assertSee('Email me a code instead');
    }

    public function test_the_email_option_is_hidden_after_a_link_login(): void
    {
        $lead = $this->leadWithTwoFactor();
        $this->post(route('login-link.consume', $this->signInTokenFor($lead)));

        $this->get(route('two-factor.login'))->assertOk()->assertDontSee('Email me a code instead');
    }

    public function test_after_an_email_reset_the_challenge_explains_why(): void
    {
        $lead = $this->leadWithTwoFactor(['password_reset_at' => now()]);
        $this->post('/login', ['email' => $lead->email, 'password' => 'correct horse battery']);

        $this->get(route('two-factor.login'))->assertSee('Use your app or a recovery code this time')->assertDontSee('Email me a code instead');
    }

    public function test_the_recovery_form_works_without_javascript(): void
    {
        $lead = $this->leadWithTwoFactor();
        $this->post('/login', ['email' => $lead->email, 'password' => 'correct horse battery']);

        $this->get(route('two-factor.login', ['recovery' => 1]))->assertSee('name="recovery_code"', false)->assertSee('Each code works once.');
    }

    public function test_a_blocked_emailed_code_lands_on_the_screen_not_an_error(): void
    {
        $lead = $this->leadWithTwoFactor();
        $this->post(route('login-link.consume', $this->signInTokenFor($lead)));

        $this->post(route('two-factor.email.send'))->assertRedirect(route('two-factor.login'))->assertSessionHasErrors('email_code');
        $this->followingRedirects()->post(route('two-factor.email.send'))->assertSee('can\'t be used after logging in with an email link');
    }

    public function test_asking_for_an_emailed_code_shows_the_email_screen(): void
    {
        $lead = $this->leadWithTwoFactor();
        $this->post('/login', ['email' => $lead->email, 'password' => 'correct horse battery']);

        $this->followingRedirects()->from(route('two-factor.login'))->post(route('two-factor.email.send'))
            ->assertSee('Check your email for a code')->assertSee('lead@example.org')->assertSee('Send a new code');
    }

    public function test_a_wrong_emailed_code_keeps_the_email_screen(): void
    {
        $lead = $this->leadWithTwoFactor();
        $this->post('/login', ['email' => $lead->email, 'password' => 'correct horse battery']);
        $this->from(route('two-factor.login'))->post(route('two-factor.email.send'));

        $this->followingRedirects()->from(route('two-factor.login'))->post(route('two-factor.email.verify'), ['code' => '000000'])
            ->assertSee('Check your email for a code')->assertSee('That code didn\'t match');
    }

    public function test_a_wrong_recovery_code_keeps_the_recovery_screen(): void
    {
        $lead = $this->leadWithTwoFactor();
        $this->post('/login', ['email' => $lead->email, 'password' => 'correct horse battery']);

        $this->followingRedirects()->from(route('two-factor.login', ['recovery' => 1]))->post(route('two-factor.login.store'), ['recovery_code' => 'nope'])
            ->assertSee('name="recovery_code"', false);
    }
}
