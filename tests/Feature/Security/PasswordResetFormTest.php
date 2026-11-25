<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** The reset form answers the same for every address and is rate limited (spec section 13). */
class PasswordResetFormTest extends TestCase
{
    use RefreshDatabase;

    private const ANSWER = 'This reset link has expired or was already used. Ask for a new one.';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function attempt(string $email): TestResponse
    {
        return $this->from(route('password.reset', ['token' => 'made-up-token']))->post(route('password.update'), [
            'token' => 'made-up-token',
            'email' => $email,
            'password' => 'a brand new password',
            'password_confirmation' => 'a brand new password',
        ]);
    }

    public function test_an_unknown_address_gets_the_expired_link_answer(): void
    {
        User::factory()->create(['email' => 'real@example.org']);

        $this->attempt('nobody@example.org')->assertSessionHasErrors(['email' => self::ANSWER]);
        $this->attempt('real@example.org')->assertSessionHasErrors(['email' => self::ANSWER]);
    }

    public function test_a_not_invited_address_gets_the_same_answer(): void
    {
        User::factory()->notInvited()->create(['email' => 'imported@example.org']);

        $unknown = $this->attempt('nobody@example.org');
        $notInvited = $this->attempt('imported@example.org');

        $notInvited->assertSessionHasErrors(['email' => self::ANSWER]);
        $this->assertSame($unknown->getStatusCode(), $notInvited->getStatusCode());
    }

    public function test_the_reset_form_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->attempt('nobody@example.org')->assertStatus(302);
        }

        $this->attempt('nobody@example.org')->assertStatus(429);
    }

    public function test_a_real_reset_still_works(): void
    {
        $user = User::factory()->create(['email' => 'real@example.org', 'password' => Hash::make('correct horse battery')]);
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => 'Real@Example.org ',
            'password' => 'a brand new password',
            'password_confirmation' => 'a brand new password',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('a brand new password', $user->fresh()->password));
    }

    public function test_the_throttled_route_is_the_one_registered(): void
    {
        $route = Route::getRoutes()->getByName('password.update');

        $this->assertContains('throttle:password-reset', $route->gatherMiddleware());
        $this->assertContains('guest', $route->gatherMiddleware());
    }

    public function test_the_password_broker_takes_at_least_750_milliseconds(): void
    {
        $this->assertGreaterThanOrEqual(750000, config('auth.timebox_duration'));

        $broker = Password::broker();
        $duration = (new \ReflectionProperty($broker, 'timeboxDuration'))->getValue($broker);

        $this->assertGreaterThanOrEqual(750000, $duration);
    }
}
