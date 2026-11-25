<?php

namespace Tests\Feature\Security;

use App\Actions\Accounts\GrantRole;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Every route that changes something refuses a form sent from another site. The test kernel skips Laravel's forgery check, so this test puts back one that doesn't. The check runs before the records in the address are looked up, so the addresses use a made-up record number that isn't any seeded record's, and the requests carry no form fields. Two routes also check that a forged request changed nothing: log out and changing your password.
 */
class ForgedRequestTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    /** What bootstrap/app.php shows when a form's token check fails and the address has a page to go back to (#80). */
    private const EXPIRED = 'This page was open too long, so nothing was saved. Please try again.';

    /** Stands in for every record in an address. No seeded record has it, so a request that got through would find nothing. */
    private const MADE_UP_ID = '987654';

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        Notification::fake();
        Mail::fake();
        $this->withoutDefer();
        Organization::factory()->create();
        $this->coordinator = User::factory()->cleared()->create();
        app(GrantRole::class)->handle(null, $this->coordinator, Role::Coordinator);
        $this->enableTwoFactor($this->coordinator);
    }

    /**
     * Every POST, PUT and DELETE route in the access matrix, with every record number in its address made up; values that aren't records (an email's key, a token) are kept.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, string>}>
     */
    public static function changingRoutes(): array
    {
        $rows = [];

        foreach (AccessMatrixTest::ROUTES as $key => $row) {
            [$method, $name] = AccessMatrixTest::methodAndName($key);
            $params = array_map(fn (string $value) => str_starts_with($value, '@') ? self::MADE_UP_ID : $value, $row['params'] ?? []);

            if ($method !== 'GET') {
                $rows["{$method} {$name}"] = [$method, $name, $params];
            }
        }

        return $rows;
    }

    #[DataProvider('changingRoutes')]
    public function test_a_form_with_no_token_is_refused_unless_the_browser_says_it_came_from_this_site(string $method, string $name, array $params): void
    {
        $url = route($name, $params);
        $this->assertNull(User::query()->find(self::MADE_UP_ID), 'The made-up record number must not be a real person.');

        $this->assertRefused($this->send($method, $url, ['Sec-Fetch-Site' => 'cross-site']), $method, $url, 'from another site');
        $this->assertRefused($this->send($method, $url, []), $method, $url, 'with no Sec-Fetch-Site header');

        // Laravel 13 lets a request through without a token when the browser says it came from this site, since a browser never sends that for another site's form.
        $response = $this->send($method, $url, ['Sec-Fetch-Site' => 'same-origin']);
        $this->assertNotSame(419, $response->status(), "{$method} {$url} from this site should pass the forgery check.");
        $this->assertNotSame(self::EXPIRED, session('status'), "{$method} {$url} from this site should pass the forgery check.");
    }

    public function test_a_forged_log_out_leaves_the_person_signed_in(): void
    {
        foreach (['from another site' => ['Sec-Fetch-Site' => 'cross-site'], 'with no Sec-Fetch-Site header' => []] as $how => $headers) {
            $this->send('POST', route('logout'), $headers)->assertStatus(419);
            $this->assertAuthenticatedAs($this->coordinator, 'web');
        }
    }

    public function test_a_forged_password_change_leaves_the_password_alone(): void
    {
        $form = ['current_password' => 'password', 'password' => 'a brand new password', 'password_confirmation' => 'a brand new password'];
        $hash = $this->coordinator->fresh()->password;

        foreach (['from another site' => ['Sec-Fetch-Site' => 'cross-site'], 'with no Sec-Fetch-Site header' => []] as $how => $headers) {
            $this->send('PUT', route('user-password.update'), $headers, $form)->assertStatus(419);
            $this->assertSame($hash, $this->coordinator->fresh()->password, "A forged password change {$how} changed the password.");
        }

        // The same form from this site goes through, so the refusals above were the forgery check and not the form.
        $this->send('PUT', route('user-password.update'), ['Sec-Fetch-Site' => 'same-origin'], $form)->assertSessionHasNoErrors();
        $this->assertNotSame($hash, $this->coordinator->fresh()->password);
    }

    private function send(string $method, string $url, array $headers, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this->actingAs($this->coordinator->fresh())->call($method, $url, $data, server: $this->transformHeadersToServerVars($headers));
    }

    /** A failed check is a 419, or, for a POST whose address also has a page, that page again with the "open too long" message (bootstrap/app.php). */
    private function assertRefused(TestResponse $response, string $method, string $url, string $how): void
    {
        if ($response->status() === 419) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertSame('POST', $method, "{$method} {$url} {$how} should get 419, got {$response->status()}.");
        $response->assertRedirect($url);
        $this->assertSame(self::EXPIRED, session('status'), "{$method} {$url} {$how} should be refused by the forgery check.");
    }
}
