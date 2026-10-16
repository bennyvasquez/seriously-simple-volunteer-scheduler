<?php

namespace Tests\Feature\Accounts;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class PasswordLoginTest extends TestCase
{
    use InteractsWithTwoFactor;
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery';

    private function volunteer(array $attributes = []): User
    {
        return User::factory()->create(['email' => 'volunteer@example.org', 'password' => Hash::make(self::PASSWORD), ...$attributes]);
    }

    public function test_a_volunteer_can_log_in_with_a_password(): void
    {
        $user = $this->volunteer();

        $this->post('/login', ['email' => 'volunteer@example.org', 'password' => self::PASSWORD])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_email_address_is_not_case_sensitive(): void
    {
        $user = $this->volunteer();

        $this->post('/login', ['email' => 'Volunteer@Example.org', 'password' => self::PASSWORD]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $this->volunteer();

        $this->post('/login', ['email' => 'volunteer@example.org', 'password' => 'wrong password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_deactivated_account_cannot_log_in(): void
    {
        $this->volunteer(['deactivated_at' => now()]);

        $this->post('/login', ['email' => 'volunteer@example.org', 'password' => self::PASSWORD]);

        $this->assertGuest();
    }

    public function test_an_account_without_a_password_cannot_log_in_with_one(): void
    {
        User::factory()->withoutPassword()->create(['email' => 'volunteer@example.org']);

        $this->post('/login', ['email' => 'volunteer@example.org', 'password' => '']);
        $this->post('/login', ['email' => 'volunteer@example.org', 'password' => 'anything at all']);

        $this->assertGuest();
    }

    public function test_someone_with_two_factor_is_asked_for_a_code(): void
    {
        $user = $this->volunteer();
        $secret = $this->enableTwoFactor($user);

        $this->post('/login', ['email' => 'volunteer@example.org', 'password' => self::PASSWORD])
            ->assertRedirect(route('two-factor.login'));
        $this->assertGuest();

        $this->post(route('two-factor.login.store'), ['code' => $this->currentCode($secret)]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_logins_are_throttled(): void
    {
        $this->volunteer();

        foreach (range(1, 5) as $attempt) {
            $this->post('/login', ['email' => 'volunteer@example.org', 'password' => 'wrong password']);
        }

        $this->post('/login', ['email' => 'volunteer@example.org', 'password' => self::PASSWORD])
            ->assertStatus(429);
        $this->assertGuest();
    }

    public function test_there_is_no_way_to_register(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }

    public function test_passkeys_are_off(): void
    {
        $this->post('/passkeys/login')->assertNotFound();
    }

    public function test_the_login_page_is_a_placeholder_for_now(): void
    {
        $this->get('/login')->assertOk()->assertSee('Log in');
    }
}
