<?php

namespace Tests\Feature\Security;

use App\Http\Responses\NeutralPasswordResetLinkResponse;
use App\Models\User;
use App\Notifications\LoginLinkNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Timebox;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/** Sign-in attempts are limited for each address and each person, and a failed password login takes a fixed minimum time (spec section 13). */
class SignInLimitsTest extends TestCase
{
    use InteractsWithTwoFactor;
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery';

    private const LINK_ANSWER = 'If that address has an account, we\'ve emailed a link. It works for 15 minutes.';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    private function login(string $email, string $password, string $ip): TestResponse
    {
        return $this->fromIp($ip)->post(route('login.store'), ['email' => $email, 'password' => $password]);
    }

    public function test_twenty_failed_logins_for_one_address_from_many_ips_stop_the_twenty_first(): void
    {
        User::factory()->create(['email' => 'victim@example.org', 'password' => Hash::make(self::PASSWORD)]);

        foreach (range(1, 20) as $i) {
            $this->login('victim@example.org', 'wrong password '.$i, '10.0.0.'.$i)->assertSessionHasErrors('email');
        }

        $this->login('Victim@Example.org', self::PASSWORD, '10.0.1.1')->assertStatus(429);
        $this->assertGuest();
    }

    public function test_the_address_limit_reads_the_same_for_an_unknown_address(): void
    {
        foreach (range(1, 20) as $i) {
            $this->login('nobody@example.org', 'wrong password '.$i, '10.0.0.'.$i)->assertSessionHasErrors('email');
        }

        $this->login('nobody@example.org', self::PASSWORD, '10.0.1.1')->assertStatus(429);
    }

    public function test_successful_logins_do_not_count_toward_the_address_limit(): void
    {
        $user = User::factory()->create(['email' => 'volunteer@example.org', 'password' => Hash::make(self::PASSWORD)]);

        foreach (range(1, 19) as $i) {
            $this->login('volunteer@example.org', 'wrong password '.$i, '10.0.0.'.$i);
        }

        foreach (range(1, 5) as $i) {
            $this->login('volunteer@example.org', self::PASSWORD, '10.0.2.'.$i)->assertRedirect('/');
            $this->assertAuthenticatedAs($user);
            Auth::logout();
        }

        // Still one failure left before the limit: the five sign-ins weren't counted.
        $this->login('volunteer@example.org', 'wrong password again', '10.0.3.1')->assertSessionHasErrors('email');
        $this->login('volunteer@example.org', self::PASSWORD, '10.0.3.2')->assertStatus(429);
    }

    public function test_a_correct_password_waiting_for_its_two_factor_code_is_not_counted(): void
    {
        $user = User::factory()->create(['email' => 'coordinator@example.org', 'password' => Hash::make(self::PASSWORD)]);
        $this->enableTwoFactor($user);

        foreach (range(1, 19) as $i) {
            $this->login('coordinator@example.org', 'wrong password '.$i, '10.0.0.'.$i);
        }

        foreach (range(1, 3) as $i) {
            $this->login('coordinator@example.org', self::PASSWORD, '10.0.2.'.$i)->assertRedirect(route('two-factor.login'));
        }

        $this->login('coordinator@example.org', 'wrong password again', '10.0.3.1')->assertSessionHasErrors('email');
        $this->login('coordinator@example.org', self::PASSWORD, '10.0.3.2')->assertStatus(429);
    }

    public function test_ten_people_on_one_ip_can_all_sign_in(): void
    {
        foreach (range(1, 10) as $i) {
            $user = User::factory()->create(['email' => "person{$i}@example.org", 'password' => Hash::make(self::PASSWORD)]);

            $this->login("person{$i}@example.org", self::PASSWORD, '192.0.2.10')->assertRedirect('/');
            $this->assertAuthenticatedAs($user);
            Auth::logout();
        }
    }

    public function test_an_eleventh_login_link_in_a_day_for_one_address_is_refused_with_the_usual_answer(): void
    {
        $user = User::factory()->withoutPassword()->create(['email' => 'victim@example.org']);

        foreach (range(1, 10) as $i) {
            $this->fromIp('10.0.0.'.$i)->from(route('login'))->post(route('login-link.request'), ['email' => 'victim@example.org'])
                ->assertRedirect(route('login'))
                ->assertSessionHas('status', self::LINK_ANSWER)
                ->assertSessionHas('login_link_sent', true);
        }

        Notification::assertSentToTimes($user, LoginLinkNotification::class, 10);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])->from(route('login'))->post(route('login-link.request'), ['email' => 'Victim@Example.org'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', self::LINK_ANSWER)
            ->assertSessionHas('login_link_sent', true)
            ->assertSessionHasInput('email', 'victim@example.org')
            ->assertSessionHasNoErrors();

        Notification::assertSentToTimes($user, LoginLinkNotification::class, 10);
    }

    public function test_an_eleventh_reset_link_request_in_a_day_is_refused_the_same_way(): void
    {
        $user = User::factory()->create(['email' => 'victim@example.org', 'password' => Hash::make(self::PASSWORD)]);

        foreach (range(1, 10) as $i) {
            // Past the reset broker's own one-minute wait between emails, so each request would send one.
            $this->travel(2)->minutes();

            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$i])->from(route('password.request'))->post(route('password.email'), ['email' => 'victim@example.org'])
                ->assertRedirect(route('password.request'))
                ->assertSessionHas('status', NeutralPasswordResetLinkResponse::MESSAGE);
        }

        Notification::assertSentToTimes($user, ResetPassword::class, 10);
        $this->travel(2)->minutes();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])->from(route('password.request'))->post(route('password.email'), ['email' => ' Victim@Example.org'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', NeutralPasswordResetLinkResponse::MESSAGE)
            ->assertSessionHas('reset_link_sent', true)
            ->assertSessionHasInput('email', 'victim@example.org')
            ->assertSessionHasNoErrors();

        Notification::assertSentToTimes($user, ResetPassword::class, 10);
    }

    public function test_reset_link_requests_from_one_ip_are_limited(): void
    {
        foreach (range(1, 5) as $i) {
            $this->post(route('password.email'), ['email' => "person{$i}@example.org"])->assertStatus(302);
        }

        $this->post(route('password.email'), ['email' => 'person6@example.org'])->assertStatus(429);
    }

    public function test_password_confirmation_is_limited_per_person(): void
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);

        foreach (range(1, 5) as $i) {
            $this->actingAs($user)->post(route('password.confirm.store'), ['password' => 'wrong password '.$i])->assertSessionHasErrors('password');
        }

        $this->actingAs($user)->post(route('password.confirm.store'), ['password' => self::PASSWORD])->assertStatus(429);

        // Another person's limit is their own.
        $other = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $this->actingAs($other)->post(route('password.confirm.store'), ['password' => self::PASSWORD])->assertSessionHasNoErrors();
    }

    public function test_password_change_is_limited_per_person(): void
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $form = ['password' => 'a brand new password', 'password_confirmation' => 'a brand new password'];

        foreach (range(1, 5) as $i) {
            $this->actingAs($user)->put(route('user-password.update'), ['current_password' => 'wrong password '.$i, ...$form])->assertSessionHasErrors();
        }

        $this->actingAs($user)->put(route('user-password.update'), ['current_password' => self::PASSWORD, ...$form])->assertStatus(429);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    public function test_a_failed_login_takes_at_least_the_timebox(): void
    {
        $timebox = $this->recordingTimebox();
        User::factory()->create(['email' => 'volunteer@example.org', 'password' => Hash::make(self::PASSWORD)]);

        foreach (['volunteer@example.org', 'nobody@example.org'] as $email) {
            $timebox->calls = [];

            $this->login($email, 'wrong password', '192.0.2.1')->assertSessionHasErrors('email');

            $this->assertSame([['duration' => 750000, 'early' => false]], $timebox->calls, $email);
        }
    }

    public function test_a_successful_login_returns_without_waiting(): void
    {
        $timebox = $this->recordingTimebox();
        User::factory()->create(['email' => 'volunteer@example.org', 'password' => Hash::make(self::PASSWORD)]);

        $this->login('volunteer@example.org', self::PASSWORD, '192.0.2.1')->assertRedirect('/');

        $this->assertNotEmpty($timebox->calls);
        foreach ($timebox->calls as $call) {
            $this->assertSame(['duration' => 750000, 'early' => true], $call);
        }
    }

    /** @return object{calls: array} */
    private function recordingTimebox(): object
    {
        $recorder = new class
        {
            public array $calls = [];
        };

        $this->app->bind(Timebox::class, fn () => new class($recorder) extends Timebox
        {
            public function __construct(private object $recorder) {}

            public function call(callable $callback, int $microseconds)
            {
                $result = $callback($this);
                $this->recorder->calls[] = ['duration' => $microseconds, 'early' => $this->earlyReturn];

                return $result;
            }
        });

        return $recorder;
    }

    /** Fortify registers these routes without a limit; routes/web.php declares them again, with the same names, controllers and access, plus the limit. */
    public function test_the_limited_routes_are_the_ones_registered(): void
    {
        $expected = [
            'password.confirm.store' => ['auth', 'throttle:password-confirm'],
            'user-password.update' => ['auth', 'throttle:password-change'],
            'password.email' => ['guest', 'throttle:password-reset-link'],
            'login-link.request' => ['guest', 'throttle:login-link'],
            'login.store' => ['throttle:login'],
        ];

        foreach ($expected as $name => $middleware) {
            $gathered = Route::getRoutes()->getByName($name)->gatherMiddleware();

            foreach ($middleware as $one) {
                $this->assertContains($one, $gathered, $name);
            }
        }
    }
}
