<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Notifications\TwoFactorAttemptsNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/** Two-factor codes have hourly and daily limits on top of the per-minute one, and the person is told once a day (spec section 13). */
class TwoFactorLimitsTest extends TestCase
{
    use InteractsWithTwoFactor;
    use RefreshDatabase;

    private User $lead;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Cache::flush();
        $this->lead = User::factory()->create(['email' => 'lead@example.org', 'password' => Hash::make('correct horse battery')]);
        $this->secret = $this->enableTwoFactor($this->lead);
        $this->withSession(['login.id' => $this->lead->id, 'login.via' => 'password']);
    }

    /** Types wrong app codes, four a minute so the per-minute limit never gets in the way, and returns the last response. */
    private function wrongCodes(int $count)
    {
        $response = null;

        for ($i = 1; $i <= $count; $i++) {
            $response = $this->post(route('two-factor.login.store'), ['code' => '000000']);

            if ($i % 4 === 0) {
                $this->travel(61)->seconds();
            }
        }

        return $response;
    }

    public function test_the_twenty_first_code_in_an_hour_is_refused(): void
    {
        $this->wrongCodes(20)->assertStatus(302);

        $this->post(route('two-factor.login.store'), ['code' => '000000'])->assertStatus(429);
    }

    public function test_the_fifty_first_code_in_a_day_is_refused(): void
    {
        foreach ([20, 20, 10] as $batch) {
            $this->wrongCodes($batch)->assertStatus(302);
            $this->travel(61)->minutes();
        }

        $this->post(route('two-factor.login.store'), ['code' => '000000'])->assertStatus(429);
    }

    public function test_the_person_is_emailed_once_a_day(): void
    {
        $this->wrongCodes(20);
        $this->post(route('two-factor.login.store'), ['code' => '000000'])->assertStatus(429);
        Notification::assertSentToTimes($this->lead, TwoFactorAttemptsNotification::class, 1);

        $this->travel(61)->minutes();
        $this->wrongCodes(20);
        $this->post(route('two-factor.login.store'), ['code' => '000000'])->assertStatus(429);

        Notification::assertSentToTimes($this->lead, TwoFactorAttemptsNotification::class, 1);

        $this->travel(25)->hours();
        $this->wrongCodes(20);
        $this->post(route('two-factor.login.store'), ['code' => '000000'])->assertStatus(429);

        Notification::assertSentToTimes($this->lead, TwoFactorAttemptsNotification::class, 2);
    }

    public function test_a_right_code_still_works_after_a_few_wrong_ones(): void
    {
        $this->wrongCodes(3);

        $this->post(route('two-factor.login.store'), ['code' => $this->currentCode($this->secret)])->assertRedirect();

        $this->assertAuthenticatedAs($this->lead);
        Notification::assertNothingSent();
    }

    public function test_the_emailed_code_route_shares_the_limits(): void
    {
        $this->wrongCodes(19);

        $this->post(route('two-factor.email.verify'), ['code' => '123456'])->assertStatus(302);
        $this->post(route('two-factor.email.verify'), ['code' => '123456'])->assertStatus(429);
        $this->post(route('two-factor.login.store'), ['code' => '000000'])->assertStatus(429);
    }

    public function test_with_no_pending_login_nobody_is_emailed(): void
    {
        $this->flushSession();

        for ($i = 1; $i <= 21; $i++) {
            $this->post(route('two-factor.email.verify'), ['code' => '123456']);

            if ($i % 4 === 0) {
                $this->travel(61)->seconds();
            }
        }

        Notification::assertNothingSent();
    }

    public function test_the_email_reads_as_drafted(): void
    {
        $mail = (new TwoFactorAttemptsNotification)->toMail($this->lead);

        $this->assertSame('Someone is trying to sign in to your account', $mail->subject);
        $this->assertSame('Hi '.$this->lead->firstName().',', $mail->greeting);
        $this->assertSame('Someone started signing in to your account and typed a lot of wrong two-factor codes.', $mail->introLines[0]);
        $this->assertSame('If that was you, there\'s nothing to do, though code attempts may be paused for a while. If it wasn\'t, someone may know your password or be able to read your email: change your password now and let a coordinator know.', $mail->introLines[1]);
        $this->assertStringContainsString(route('password.request'), (string) $mail->introLines[2]);
        $this->assertStringContainsString('Change your password', (string) $mail->introLines[2]);
        $this->assertStringNotContainsString(route('account.security'), (string) $mail->introLines[2]);
    }
}
