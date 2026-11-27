<?php

namespace Tests\Feature\Screens;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginScreensTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_page_offers_the_link_first(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'Log in with my password'), strpos($html, 'Email me a link'));
        $this->assertStringContainsString('Anyone can add a password from their profile.', $html);
    }

    public function test_the_or_between_the_two_ways_to_log_in_is_a_divider(): void
    {
        $this->get('/login')->assertSee('<p class="divider">or</p>', false);
    }

    public function test_the_login_page_works_without_javascript_and_clears_the_password_for_a_link(): void
    {
        $html = $this->get('/login')->getContent();

        // Without JavaScript both buttons still submit the one form, the password button to the password login.
        $this->assertStringContainsString('<button type="submit" class="btn btn-primary" id="send-link">Email me a link</button>', $html);
        $this->assertStringContainsString('formaction="'.route('login').'"', $html);
        // With it, "Email me a link" doesn't send a typed password along.
        $this->assertStringContainsString('<script src="'.asset('js/login.js').'" defer></script>', $html);
        $this->assertMatchesRegularExpression("/submitter.*'send-link'.*password/s", file_get_contents(public_path('js/login.js')));
    }

    public function test_enter_in_the_password_box_logs_in_with_the_password_when_javascript_runs(): void
    {
        $html = $this->get('/login')->getContent();

        // Browsers submit with the form's first button, "Email me a link". With JavaScript, Enter in the password box presses the password button instead.
        $this->assertStringContainsString('id="password-login"', $html);
        $this->assertMatchesRegularExpression("/'Enter'.*password-login.*requestSubmit\(/s", file_get_contents(public_path('js/login.js')));
    }

    public function test_asking_for_a_link_shows_check_your_email_with_the_address(): void
    {
        $this->from('/login')->post(route('login-link.request'), ['email' => 'nobody@example.org'])
            ->assertRedirect('/login');

        $this->followingRedirects()->from('/login')->post(route('login-link.request'), ['email' => 'nobody@example.org'])
            ->assertSee('Check your email')->assertSee('If nobody@example.org has an account');
    }

    public function test_a_plain_status_shows_on_the_normal_login_screen(): void
    {
        $this->withSession(['status' => 'You\'re signed out on every device.'])->get('/login')
            ->assertOk()->assertSee('signed out on every device')->assertSee('Log in with my password')->assertDontSee('Check your email');
    }

    public function test_a_failed_password_login_points_to_the_email_link(): void
    {
        User::factory()->withoutPassword()->create(['email' => 'volunteer@example.org']);

        $this->followingRedirects()->from('/login')->post('/login', ['email' => 'volunteer@example.org', 'password' => 'anything at all'])
            ->assertSee('If you\'ve never set a password, use "Email me a link" instead.');
    }

    public function test_the_reset_screens_use_plain_language(): void
    {
        $this->get(route('password.request'))->assertOk()->assertSee('Reset your password')->assertSee('Never set a password?');
        $this->get(route('password.reset', ['token' => 'not-a-real-token', 'email' => 'sam@example.org']))->assertOk()->assertSee('Choose a new password')->assertSee('sam@example.org');
    }

    public function test_asking_for_a_reset_shows_the_check_your_email_state(): void
    {
        $this->followingRedirects()->from(route('password.request'))->post(route('password.email'), ['email' => 'nobody@example.org'])
            ->assertSee('Check your email')->assertSee('If nobody@example.org has an account')->assertDontSee('After pressing the button');
    }

    public function test_the_sent_states_never_show_an_empty_address(): void
    {
        $this->withSession(['reset_link_sent' => true])->get(route('password.request'))->assertSee('If that address has an account');
        $this->withSession(['login_link_sent' => true])->get('/login')->assertSee('If that address has an account');
    }

    public function test_a_plain_status_does_not_show_the_reset_sent_state(): void
    {
        $this->withSession(['status' => 'Something else.'])->get(route('password.request'))->assertDontSee('Check your email')->assertSee('Email me a reset link');
    }

    public function test_the_confirm_password_screen_says_why(): void
    {
        $this->actingAs(User::factory()->create())->get(route('password.confirm'))->assertOk()->assertSee('Confirm it\'s you', false);
    }

    public function test_the_reset_screen_offers_a_new_link(): void
    {
        $this->get(route('password.reset', ['token' => 'not-a-real-token', 'email' => 'sam@example.org']))
            ->assertSee('<a class="muted" href="'.route('password.request').'">Ask for a new reset link</a>', false);
    }

    public function test_the_confirm_password_screen_has_a_way_out(): void
    {
        $this->actingAs(User::factory()->create())->get(route('password.confirm'))
            ->assertSee('<form method="POST" action="'.route('account.forgot-password').'">', false)
            ->assertSee('Forgot your password?')
            ->assertSee('href="'.route('account.show').'"', false);
    }

    public function test_the_confirm_screen_says_forgot_your_password_logs_you_out(): void
    {
        $this->actingAs(User::factory()->create())->get(route('password.confirm'))
            ->assertSeeInOrder(['Forgot your password?', 'This logs you out so you can reset it by email.']);
    }

    public function test_forgot_your_password_from_the_confirm_screen_logs_out_and_opens_the_reset_screen(): void
    {
        $this->actingAs(User::factory()->create())->post(route('account.forgot-password'))->assertRedirect(route('password.request'));

        $this->assertGuest();
        $this->get(route('password.request'))->assertOk()->assertSee('Reset your password');
    }

    public function test_the_login_screens_use_no_organization_specific_words(): void
    {
        $this->withSession(['login_link_sent' => true])->get('/login')->assertSee('make sure you typed the address you signed up with')->assertDontSee('makerspace');
    }
}
