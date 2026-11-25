<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\HideRateLimitHeaders;
use App\Http\Responses\NeutralPasswordResetLinkResponse;
use App\Models\User;
use App\Notifications\LoginLinkNotification;
use App\Providers\FortifyServiceProvider;
use App\Support\EmailAddress;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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

    /** Swaps in a hasher that counts the hashes it makes and checks, passing each call on to the real one. */
    private function countingHasher(): object
    {
        $hasher = new class(app('hash')) implements Hasher
        {
            public int $made = 0;

            public int $checked = 0;

            public function __construct(private Hasher $real) {}

            public function info($hashedValue)
            {
                return $this->real->info($hashedValue);
            }

            public function make($value, array $options = [])
            {
                $this->made++;

                return $this->real->make($value, $options);
            }

            public function check($value, $hashedValue, array $options = [])
            {
                $this->checked++;

                return $this->real->check($value, $hashedValue, $options);
            }

            public function needsRehash($hashedValue, array $options = [])
            {
                return $this->real->needsRehash($hashedValue, $options);
            }

            public function __call($method, $parameters)
            {
                return $this->real->{$method}(...$parameters);
            }
        };
        $this->swap('hash', $hasher);

        return $hasher;
    }

    public function test_the_hash_for_unknown_addresses_is_made_once_and_kept(): void
    {
        $hasher = $this->countingHasher();

        // A cold cache: the first failed login for an unknown address makes the hash, at most once.
        $this->login('nobody@example.org', 'wrong password', '192.0.2.1')->assertSessionHasErrors('email');
        $this->assertLessThanOrEqual(1, $hasher->made);
        $this->assertTrue(Cache::has(FortifyServiceProvider::dummyHashKey()));

        // Once it's kept, no later request makes one, whatever process answers it, and each still does one check.
        $hasher->made = $hasher->checked = 0;
        foreach (['nobody@example.org', 'someone.else@example.org'] as $i => $email) {
            $this->login($email, 'wrong password', '192.0.2.'.($i + 2))->assertSessionHasErrors('email');
        }
        $this->assertSame(0, $hasher->made);
        $this->assertSame(2, $hasher->checked);
    }

    public function test_blank_and_unusable_addresses_share_no_address_limit(): void
    {
        foreach (range(1, 22) as $i) {
            $this->login('', 'wrong password', '10.0.0.'.$i)->assertSessionHasErrors('email');
            $this->login('nöbody@example.org', 'wrong password', '10.0.1.'.$i)->assertSessionHasErrors('email');
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

    public function test_the_tenth_and_eleventh_login_link_answers_look_the_same(): void
    {
        User::factory()->withoutPassword()->create(['email' => 'victim@example.org']);

        foreach (range(1, 9) as $i) {
            $this->fromIp('10.0.0.'.$i)->from(route('login'))->post(route('login-link.request'), ['email' => 'victim@example.org']);
        }

        $tenth = $this->fromIp('10.0.0.10')->from(route('login'))->post(route('login-link.request'), ['email' => 'victim@example.org']);
        $tenthStatus = session('status');
        $eleventh = $this->fromIp('10.0.0.11')->from(route('login'))->post(route('login-link.request'), ['email' => 'victim@example.org']);

        $this->assertSameAnswer($tenth, $eleventh);
        $this->assertSame($tenthStatus, session('status'));
        Notification::assertSentTimes(LoginLinkNotification::class, 10);
    }

    public function test_the_tenth_and_eleventh_reset_link_answers_look_the_same(): void
    {
        User::factory()->create(['email' => 'victim@example.org', 'password' => Hash::make(self::PASSWORD)]);

        foreach (range(1, 9) as $i) {
            $this->travel(2)->minutes();
            $this->fromIp('10.0.0.'.$i)->from(route('password.request'))->post(route('password.email'), ['email' => 'victim@example.org']);
        }

        $this->travel(2)->minutes();
        $tenth = $this->fromIp('10.0.0.10')->from(route('password.request'))->post(route('password.email'), ['email' => 'victim@example.org']);
        $tenthStatus = session('status');
        $this->travel(2)->minutes();
        $eleventh = $this->fromIp('10.0.0.11')->from(route('password.request'))->post(route('password.email'), ['email' => 'victim@example.org']);

        $this->assertSameAnswer($tenth, $eleventh);
        $this->assertSame($tenthStatus, session('status'));
        Notification::assertSentTimes(ResetPassword::class, 10);
    }

    public function test_the_capped_reset_link_answer_takes_the_fixed_time(): void
    {
        foreach (range(1, 10) as $i) {
            $this->fromIp('10.0.0.'.$i)->post(route('password.email'), ['email' => 'nobody@example.org']);
        }

        $timebox = $this->recordingTimebox();

        $this->fromIp('10.0.0.11')->from(route('password.request'))->post(route('password.email'), ['email' => 'nobody@example.org'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', NeutralPasswordResetLinkResponse::MESSAGE);

        $this->assertSame([['duration' => 750000, 'early' => false]], $timebox->calls);
    }

    public function test_sign_in_answers_carry_no_rate_limit_headers(): void
    {
        User::factory()->create(['email' => 'volunteer@example.org', 'password' => Hash::make(self::PASSWORD)]);

        $answers = [
            $this->post(route('login.store'), ['email' => 'volunteer@example.org', 'password' => 'wrong password']),
            $this->post(route('login-link.request'), ['email' => 'volunteer@example.org']),
            $this->post(route('password.email'), ['email' => 'volunteer@example.org']),
        ];

        foreach (range(1, 4) as $i) {
            $answers[] = $this->post(route('login.store'), ['email' => 'volunteer@example.org', 'password' => 'wrong password']);
        }
        $answers[] = $this->post(route('login.store'), ['email' => 'volunteer@example.org', 'password' => 'wrong password'])->assertStatus(429);

        foreach ($answers as $answer) {
            foreach (HideRateLimitHeaders::HEADERS as $header) {
                $this->assertFalse($answer->headers->has($header), $header);
            }
        }
    }

    public function test_a_blank_or_unusable_address_gets_its_usual_message_however_often_it_is_sent(): void
    {
        foreach (range(1, 12) as $i) {
            $this->fromIp('10.0.0.'.$i)->post(route('login-link.request'), ['email' => ''])->assertSessionHasErrors('email');
            $this->fromIp('10.0.0.'.$i)->post(route('login-link.request'), ['email' => 'nöbody@example.org'])->assertSessionHasErrors(['email' => EmailAddress::REFUSAL]);
            $this->fromIp('10.0.1.'.$i)->post(route('password.email'), ['email' => ''])->assertSessionHasErrors('email');
            $this->fromIp('10.0.2.'.$i)->post(route('password.email'), ['email' => 'nöbody@example.org'])->assertSessionHasErrors(['email' => EmailAddress::REFUSAL]);
        }
    }

    public function test_the_header_middleware_wraps_the_throttle(): void
    {
        // Making the HTTP kernel gives the router its middleware groups and priority list, which resolveMiddleware() expands and sorts the way a request does.
        $this->app->make(Kernel::class);
        $router = app('router');

        foreach (['login.store', 'login-link.request', 'password.email'] as $name) {
            $route = $router->getRoutes()->getByName($name);
            $middleware = $router->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware());
            $hide = array_search(HideRateLimitHeaders::class, $middleware, true);
            $throttle = collect($middleware)->search(fn ($one) => str_starts_with($one, ThrottleRequests::class));

            $this->assertIsInt($hide, $name);
            $this->assertIsInt($throttle, $name);
            $this->assertLessThan($throttle, $hide, $name);
        }
    }

    private function assertSameAnswer(TestResponse $normal, TestResponse $capped): void
    {
        $this->assertSame($normal->getStatusCode(), $capped->getStatusCode());
        $this->assertSame($normal->headers->get('Location'), $capped->headers->get('Location'));

        $names = fn (TestResponse $response) => collect(array_keys($response->headers->all()))->sort()->values()->all();
        $this->assertSame($names($normal), $names($capped));
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
