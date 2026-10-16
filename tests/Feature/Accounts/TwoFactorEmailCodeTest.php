<?php

namespace Tests\Feature\Accounts;

use App\Actions\Accounts\IssueLoginLink;
use App\Enums\LoginLinkPurpose;
use App\Models\ChangeLogEntry;
use App\Models\User;
use App\Notifications\TwoFactorCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class TwoFactorEmailCodeTest extends TestCase
{
    use InteractsWithTwoFactor;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->user = User::factory()->create(['email' => 'coordinator@example.org', 'password' => Hash::make('correct horse battery')]);
        $this->enableTwoFactor($this->user);
    }

    private function logInWithPassword(): void
    {
        $this->post('/login', ['email' => 'coordinator@example.org', 'password' => 'correct horse battery'])
            ->assertRedirect(route('two-factor.login'));
    }

    private function sentCode(): string
    {
        $code = null;
        Notification::assertSentTo($this->user, TwoFactorCodeNotification::class, function (TwoFactorCodeNotification $notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        return $code;
    }

    public function test_after_a_password_login_an_emailed_code_works(): void
    {
        $this->logInWithPassword();

        $this->post(route('two-factor.email.send'))->assertSessionHas('status');
        $this->post(route('two-factor.email.verify'), ['code' => $this->sentCode()])->assertRedirect('/');

        $this->assertAuthenticatedAs($this->user);
        $this->assertTrue(ChangeLogEntry::query()->where('action', 'two_factor.email_code_used')->exists());
    }

    public function test_an_emailed_code_is_refused_after_an_email_link_login(): void
    {
        $url = app(IssueLoginLink::class)->handle($this->user, LoginLinkPurpose::SignIn);
        $this->post(route('login-link.consume', basename(parse_url($url, PHP_URL_PATH))))
            ->assertRedirect(route('two-factor.login'));

        $this->post(route('two-factor.email.send'))->assertForbidden();

        Notification::assertNotSentTo($this->user, TwoFactorCodeNotification::class);
        $this->assertGuest();
    }

    public function test_a_password_login_after_a_link_login_can_use_an_emailed_code(): void
    {
        $url = app(IssueLoginLink::class)->handle($this->user, LoginLinkPurpose::SignIn);
        $this->post(route('login-link.consume', basename(parse_url($url, PHP_URL_PATH))));

        $this->logInWithPassword();

        $this->post(route('two-factor.email.send'))->assertSessionHas('status');
    }

    public function test_a_wrong_code_is_refused(): void
    {
        $this->logInWithPassword();
        $this->post(route('two-factor.email.send'));
        $right = $this->sentCode();
        $wrong = $right === '000000' ? '111111' : '000000';

        $this->post(route('two-factor.email.verify'), ['code' => $wrong])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_five_wrong_tries_use_up_the_code(): void
    {
        $this->logInWithPassword();
        $this->post(route('two-factor.email.send'));
        $right = $this->sentCode();
        $wrong = $right === '000000' ? '111111' : '000000';

        foreach (range(1, 5) as $attempt) {
            $this->post(route('two-factor.email.verify'), ['code' => $wrong]);
        }
        $this->travel(2)->minutes(); // Past the two-factor rate limit window.

        $this->post(route('two-factor.email.verify'), ['code' => $right])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_a_code_expires_after_10_minutes(): void
    {
        $this->logInWithPassword();
        $this->post(route('two-factor.email.send'));
        $code = $this->sentCode();

        $this->travel(11)->minutes();

        $this->post(route('two-factor.email.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_without_a_pending_login_you_are_sent_to_log_in(): void
    {
        $this->post(route('two-factor.email.send'))->assertRedirect(route('login'));
        $this->post(route('two-factor.email.verify'), ['code' => '123456'])->assertRedirect(route('login'));
    }
}
