<?php

namespace Tests\Feature\Accounts;

use App\Models\ChangeLogEntry;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    public function test_a_deactivated_address_gets_the_same_answer_and_no_email(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'leaving@example.org', 'deactivated_at' => now()]);

        $this->post('/forgot-password', ['email' => 'leaving@example.org'])
            ->assertSessionHas('status', self::ANSWER)
            ->assertSessionHasNoErrors();

        Notification::assertNotSentTo($user, ResetPassword::class);
    }

    public function test_changing_your_password_is_logged(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct horse battery')]);

        $this->actingAs($user)
            ->put(route('user-password.update'), ['current_password' => 'correct horse battery', 'password' => 'a brand new password', 'password_confirmation' => 'a brand new password'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('a brand new password', $user->fresh()->password));
        $this->assertTrue(ChangeLogEntry::query()->where('action', 'user.password_changed')->where('actor_id', $user->id)->where('subject_id', $user->id)->exists());
    }
}
