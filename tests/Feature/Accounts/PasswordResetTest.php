<?php

namespace Tests\Feature\Accounts;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const ANSWER = 'If that address has an account, we\'ve emailed a link to reset your password.';

    public function test_a_known_address_is_sent_a_reset_link(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'volunteer@example.org']);

        $this->post('/forgot-password', ['email' => 'Volunteer@Example.org'])
            ->assertSessionHas('status', self::ANSWER)
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_an_unknown_address_gets_the_same_answer(): void
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'nobody@example.org'])
            ->assertSessionHas('status', self::ANSWER)
            ->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_asking_twice_in_a_row_gets_the_same_answer(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'volunteer@example.org']);

        $this->post('/forgot-password', ['email' => 'volunteer@example.org']);
        $this->post('/forgot-password', ['email' => 'volunteer@example.org'])
            ->assertSessionHas('status', self::ANSWER)
            ->assertSessionHasNoErrors();
    }
}
