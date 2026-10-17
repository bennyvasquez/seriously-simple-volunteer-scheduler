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
            ->assertSee('Check your email')->assertSee('If nobody@example.org has an account');
    }

    public function test_the_confirm_password_screen_says_why(): void
    {
        $this->actingAs(User::factory()->create())->get(route('password.confirm'))->assertOk()->assertSee('Confirm it\'s you', false);
    }
}
