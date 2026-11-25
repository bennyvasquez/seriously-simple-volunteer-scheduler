<?php

namespace Tests\Feature\Security;

use App\Actions\Accounts\CreatePerson;
use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\RequestEmailChange;
use App\Enums\Role;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\EmailAddress;
use DomainException;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use ReflectionProperty;
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

    public function test_up_answers_during_maintenance(): void
    {
        $this->artisan('down')->assertSuccessful();

        try {
            $this->get('/up')->assertOk();
            $this->get('/login')->assertStatus(503);
        } finally {
            $this->artisan('up');
        }
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

    public function test_migrate_fresh_refuses_in_production_even_with_force(): void
    {
        $person = User::factory()->create();
        $this->bootAs('production');

        $this->assertNotSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        $this->assertDatabaseHas('users', ['id' => $person->id]);
    }

    public function test_the_other_destructive_commands_refuse_in_production_too(): void
    {
        $person = User::factory()->create();
        $this->bootAs('production');

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
