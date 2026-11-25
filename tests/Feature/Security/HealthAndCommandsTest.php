<?php

namespace Tests\Feature\Security;

use App\Actions\Accounts\CreatePerson;
use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\RequestEmailChange;
use App\Enums\Role;
use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\EmailAddress;
use DomainException;
use Illuminate\Container\Container;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ReflectionProperty;
use RuntimeException;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class HealthAndCommandsTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    protected function tearDown(): void
    {
        DB::prohibitDestructiveCommands(false);

        parent::tearDown();
    }

    public function test_up_is_plain_text_with_no_outside_scripts(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
        $this->assertSame('OK', $response->getContent());
        $this->assertStringNotContainsString('<script', $response->getContent());
    }

    /** Runs $check with the app in maintenance mode through a real `artisan down`, kept in a temporary storage folder so the developer's own app never goes down. */
    private function duringMaintenance(callable $check): void
    {
        $realDownFile = storage_path('framework/down');
        $realWasDown = is_file($realDownFile);
        $storage = sys_get_temp_dir().'/ssvs-maintenance-'.Str::random(12);
        File::ensureDirectoryExists($storage.'/framework');
        $this->app->useStoragePath($storage);

        try {
            $this->artisan('down')->assertSuccessful();
            $this->assertFileExists($storage.'/framework/down');
            $this->assertSame($realWasDown, is_file($realDownFile));

            $check();
        } finally {
            $this->artisan('up');
            File::deleteDirectory($storage);
        }
    }

    public function test_up_answers_during_maintenance(): void
    {
        $this->duringMaintenance(function () {
            $this->get('/up')->assertOk();
            $this->get('/login')->assertStatus(503);
        });
    }

    public function test_up_is_exempt_from_maintenance_without_loading_any_routes(): void
    {
        $test = $this->app;
        PreventRequestsDuringMaintenance::flushState();

        try {
            // A fresh app whose HTTP kernel is made but never handles a request, so no route file runs: what a server with cached routes has.
            $fresh = require base_path('bootstrap/app.php');
            $fresh->make(HttpKernel::class);
        } finally {
            Container::setInstance($test);
        }

        $this->assertContains('up', (new ReflectionProperty(PreventRequestsDuringMaintenance::class, 'neverPrevent'))->getValue());
    }

    public function test_up_answers_during_maintenance_when_routes_are_not_loaded_again(): void
    {
        // With cached routes, the routing callbacks in bootstrap/app.php never run. Clear what they may have set, then build the HTTP kernel again the way a request on such a server does.
        PreventRequestsDuringMaintenance::flushState();
        $this->app->forgetInstance(HttpKernel::class);
        $this->app->make(HttpKernel::class);

        $this->duringMaintenance(function () {
            $this->get('/up')->assertOk();
            $this->get('/login')->assertStatus(503);
        });
    }

    public function test_the_maintenance_page_carries_the_security_headers(): void
    {
        $this->duringMaintenance(function () {
            $this->get('/login')->assertStatus(503)
                ->assertHeader('Content-Security-Policy', SecurityHeaders::POLICY)
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Referrer-Policy', 'same-origin');
        });
    }

    public function test_up_answers_500_when_a_health_listener_throws(): void
    {
        Event::listen(DiagnosingHealth::class, fn () => throw new RuntimeException('Database is down.'));

        $response = $this->get('/up');

        $response->assertStatus(500);
        $this->assertStringNotContainsString('Database is down', $response->getContent());
    }

    public function test_up_carries_the_content_security_policy(): void
    {
        $this->get('/up')->assertHeader('Content-Security-Policy', SecurityHeaders::POLICY);
    }

    public function test_an_email_change_form_with_a_zero_width_address_shows_a_validation_error(): void
    {
        $user = User::factory()->create(['email' => 'sam@example.org']);

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('account.email.edit'))
            ->post(route('account.email.update'), ['email' => "sam\u{200B}.new@example.org"])
            ->assertRedirect(route('account.email.edit'))
            ->assertSessionHasErrors(['email' => EmailAddress::REFUSAL]);
    }

    public function test_up_sets_no_session_cookie(): void
    {
        $response = $this->get('/up');

        $this->assertSame([], $response->headers->getCookies());
    }

    /** Runs the provider's boot for the named environment, the way a real server would at start-up. */
    private function bootAs(string $environment): void
    {
        $this->app['env'] = $environment;
        (new AppServiceProvider($this->app))->boot();
    }

    /** Stops the test before any real command runs if a command isn't blocked, so a regression can't wipe the test database. */
    private function assertEveryDestructiveCommandIsBlocked(): void
    {
        foreach ([FreshCommand::class, RefreshCommand::class, ResetCommand::class, WipeCommand::class] as $class) {
            $this->assertTrue((new ReflectionProperty($class, 'prohibitedFromRunning'))->getValue(), "{$class} isn't blocked.");
        }
    }

    public function test_migrate_fresh_refuses_in_production_even_with_force(): void
    {
        $person = User::factory()->create();
        $this->bootAs('production');
        $this->assertEveryDestructiveCommandIsBlocked();

        $this->assertNotSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        $this->assertDatabaseHas('users', ['id' => $person->id]);
    }

    public function test_the_other_destructive_commands_refuse_in_production_too(): void
    {
        $person = User::factory()->create();
        $this->bootAs('production');
        $this->assertEveryDestructiveCommandIsBlocked();

        foreach (['db:wipe', 'migrate:refresh', 'migrate:reset'] as $command) {
            $this->assertNotSame(0, Artisan::call($command, ['--force' => true]), $command);
        }

        $this->assertDatabaseHas('users', ['id' => $person->id]);
    }

    public function test_the_commands_are_allowed_locally_and_in_testing(): void
    {
        foreach (['local', 'testing'] as $environment) {
            $this->bootAs('production');
            $this->assertTrue($this->prohibited(), 'setup');

            $this->bootAs($environment);
            $this->assertFalse($this->prohibited(), $environment);
        }
    }

    private function prohibited(): bool
    {
        return (new ReflectionProperty(WipeCommand::class, 'prohibitedFromRunning'))->getValue();
    }

    public function test_create_person_refuses_a_non_plain_address(): void
    {
        try {
            app(CreatePerson::class)->handle(null, 'Jordan Example', "jordan\u{200B}x@example.org");
            $this->fail('A non-plain address was accepted.');
        } catch (DomainException $e) {
            $this->assertSame(EmailAddress::REFUSAL, $e->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_an_email_change_to_a_non_plain_address_is_refused(): void
    {
        Notification::fake();
        $this->withoutDefer();
        $user = User::factory()->create();

        try {
            app(RequestEmailChange::class)->handle($user, "new\u{200B}@example.org");
            $this->fail('A non-plain address was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame(EmailAddress::REFUSAL, $e->errors()['email'][0]);
        }

        Notification::assertNothingSent();
    }

    public function test_a_volunteer_gets_403_for_any_person_security_page_real_or_not(): void
    {
        $volunteer = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($volunteer)->get("/people/{$other->id}/security")->assertForbidden();
        $this->actingAs($volunteer)->get('/people/999999/security')->assertForbidden();
        $this->actingAs($volunteer)->post("/people/{$other->id}/two-factor-reset")->assertForbidden();
        $this->actingAs($volunteer)->post('/people/999999/two-factor-reset')->assertForbidden();
    }

    public function test_a_coordinator_gets_403_for_any_person_security_page_real_or_not(): void
    {
        $coordinator = User::factory()->create();
        app(GrantRole::class)->handle(null, $coordinator, Role::Coordinator);
        $this->enableTwoFactor($coordinator);
        $other = User::factory()->create();

        $this->actingAs($coordinator)->get("/people/{$other->id}/security")->assertForbidden();
        $this->actingAs($coordinator)->get('/people/999999/security')->assertForbidden();
    }
}
