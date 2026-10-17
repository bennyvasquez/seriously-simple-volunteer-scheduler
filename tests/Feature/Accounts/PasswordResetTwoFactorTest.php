<?php

namespace Tests\Feature\Accounts;

use App\Models\ChangeLogEntry;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\TwoFactorCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/** A reset uses only the inbox, so until the person next passes the app or recovery code challenge the inbox can't also be the second factor (spec section 13). */
class PasswordResetTwoFactorTest extends TestCase
{
    use InteractsWithTwoFactor;
    use RefreshDatabase;

    private const NEW_PASSWORD = 'a brand new password';

    private User $user;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->user = User::factory()->create(['email' => 'coordinator@example.org', 'password' => Hash::make('correct horse battery')]);
        $this->secret = $this->enableTwoFactor($this->user);
    }

    private function resetPasswordByEmail(): void
    {
        $token = Password::broker()->createToken($this->user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => 'coordinator@example.org',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $this->user->fresh()->password));
    }

    private function logInWithNewPassword(): void
    {
        $this->post('/login', ['email' => 'coordinator@example.org', 'password' => self::NEW_PASSWORD])
            ->assertRedirect(route('two-factor.login'));
    }

    public function test_after_a_reset_by_email_the_emailed_code_is_refused(): void
    {
        $this->resetPasswordByEmail();
        $this->logInWithNewPassword();

        $this->post(route('two-factor.email.send'))
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionHasErrors(['email_code' => 'Your password was just reset by email, so use your authenticator app or a recovery code this time.']);

        Notification::assertNotSentTo($this->user, TwoFactorCodeNotification::class);
        $this->assertGuest();
    }

    public function test_after_an_authenticator_app_login_the_emailed_code_works_again(): void
    {
        $this->resetPasswordByEmail();
        $this->logInWithNewPassword();
        $this->post(route('two-factor.login.store'), ['code' => $this->currentCode($this->secret)])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->user);
        $this->assertNull($this->user->fresh()->password_reset_at);

        $this->post(route('logout'));
        $this->logInWithNewPassword();

        $this->post(route('two-factor.email.send'))->assertSessionHas('status');
        Notification::assertSentTo($this->user, TwoFactorCodeNotification::class);
    }

    public function test_after_a_recovery_code_login_the_emailed_code_works_again(): void
    {
        $this->resetPasswordByEmail();
        $this->logInWithNewPassword();
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-one'])->assertRedirect('/');
        $this->assertNull($this->user->fresh()->password_reset_at);

        $this->post(route('logout'));
        $this->logInWithNewPassword();

        $this->post(route('two-factor.email.send'))->assertSessionHas('status');
    }

    public function test_a_wrong_authenticator_code_does_not_lift_the_refusal(): void
    {
        $this->resetPasswordByEmail();
        $this->logInWithNewPassword();

        $wrong = $this->currentCode($this->secret) === '000000' ? '111111' : '000000';

        $this->post(route('two-factor.login.store'), ['code' => $wrong]);

        $this->assertNotNull($this->user->fresh()->password_reset_at);
        $this->post(route('two-factor.email.send'))->assertRedirect(route('two-factor.login'))->assertSessionHasErrors('email_code');
    }

    public function test_a_reset_is_logged_and_the_person_is_told(): void
    {
        $this->resetPasswordByEmail();

        $this->assertNotNull($this->user->fresh()->password_reset_at);
        $this->assertTrue(ChangeLogEntry::query()
            ->where('action', 'user.password_reset')
            ->where('actor_id', $this->user->id)
            ->where('subject_id', $this->user->id)
            ->exists());
        Notification::assertSentTo($this->user, PasswordChangedNotification::class);
    }

    public function test_using_a_recovery_code_is_logged(): void
    {
        $this->post('/login', ['email' => 'coordinator@example.org', 'password' => 'correct horse battery'])
            ->assertRedirect(route('two-factor.login'));
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-one'])->assertRedirect('/');

        $entry = ChangeLogEntry::query()->where('action', 'two_factor.recovery_code_used')->sole();
        $this->assertSame($this->user->id, $entry->affected_user_id);
        $this->assertStringNotContainsString('recovery-code', json_encode($entry->getAttributes()));
    }
}
