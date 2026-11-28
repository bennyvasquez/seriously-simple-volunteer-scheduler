<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Support\SchedulerHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CheckInstallCommandTest extends TestCase
{
    use RefreshDatabase;

    private function goodInstall(): void
    {
        config([
            'app.env' => 'production',
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'app.url' => 'https://volunteers.example.net',
            'app.trusted_proxies' => null,
            'mail.default' => 'smtp',
            'mail.from.address' => 'volunteers@makers.test',
            'session.driver' => 'database',
            'session.secure' => true,
        ]);
        Organization::factory()->create();
        SchedulerHeartbeat::beat();
    }

    public function test_a_production_install_passes(): void
    {
        $this->goodInstall();

        $this->artisan('ssvs:check-install')
            ->doesntExpectOutputToContain('FIX')
            ->assertExitCode(0);
    }

    /** @return array<string, array{string}> */
    public static function badUrls(): array
    {
        return [
            'http' => ['http://ssvs.example.org'],
            'trailing slash' => ['https://ssvs.example.org/'],
            'path' => ['https://ssvs.example.org/app'],
            'no scheme' => ['ssvs.example.org'],
        ];
    }

    #[DataProvider('badUrls')]
    public function test_app_url_problems_are_named(string $url): void
    {
        $this->goodInstall();
        config(['app.url' => $url]);

        $this->artisan('ssvs:check-install')
            ->expectsOutputToContain('APP_URL is "'.$url.'". Set it to the site\'s public address, like https://ssvs.example.org, with no path or trailing slash.')
            ->assertExitCode(1);
    }

    /** @return array<string, array{string, bool}> */
    public static function exampleUrls(): array
    {
        return [
            'the template address' => ['https://ssvs.example.org', false],
            'example.com itself' => ['https://example.com', false],
            'a non-example address (control)' => ['https://volunteers.example.net', true],
        ];
    }

    #[DataProvider('exampleUrls')]
    public function test_an_example_app_url_fails(string $url, bool $passes): void
    {
        $this->goodInstall();
        config(['app.url' => $url]);

        $fix = 'APP_URL is "'.$url.'", an example address. Set it to the site\'s public address.';

        if ($passes) {
            $this->artisan('ssvs:check-install')
                ->expectsOutputToContain('OK  Site address is '.$url)
                ->doesntExpectOutputToContain('FIX')
                ->assertExitCode(0);
        } else {
            $this->artisan('ssvs:check-install')
                ->expectsOutputToContain('FIX Site address: '.$fix)
                ->assertExitCode(1);
        }
    }

    public function test_a_missing_app_key_fails(): void
    {
        $this->goodInstall();
        config(['app.key' => '']);

        $this->artisan('ssvs:check-install')
            ->expectsOutputToContain('APP_KEY is empty. Run php artisan key:generate once, then keep a copy of .env somewhere safe off the server.')
            ->assertExitCode(1);
    }

    public function test_the_log_mailer_fails(): void
    {
        $this->goodInstall();
        config(['mail.default' => 'log']);

        $this->artisan('ssvs:check-install')
            ->expectsOutputToContain('MAIL_MAILER is "log". Set it to smtp and fill in MAIL_HOST, MAIL_PORT, MAIL_USERNAME and MAIL_PASSWORD.')
            ->assertExitCode(1);
    }

    public function test_an_example_from_address_fails(): void
    {
        $this->goodInstall();
        config(['mail.from.address' => 'hello@example.com']);

        $this->artisan('ssvs:check-install')
            ->expectsOutputToContain('MAIL_FROM_ADDRESS is "hello@example.com". Set it to an address your organization reads.')
            ->assertExitCode(1);
    }

    public function test_sessions_must_be_database_and_secure(): void
    {
        $this->goodInstall();
        config(['session.driver' => 'file', 'session.secure' => false]);

        $this->artisan('ssvs:check-install')
            ->expectsOutputToContain('SESSION_DRIVER is "file". Set SESSION_DRIVER=database, which signing out everywhere needs.')
            ->expectsOutputToContain("SESSION_SECURE_COOKIE isn't on. Set SESSION_SECURE_COOKIE=true.")
            ->assertExitCode(1);
    }

    public function test_pending_migrations_fail(): void
    {
        // Forgetting the newest migration's row makes it pending again. RefreshDatabase wraps each test in a transaction, so the row comes back afterwards.
        $this->goodInstall();
        DB::table('migrations')->orderByDesc('id')->limit(1)->delete();

        $this->artisan('ssvs:check-install')
            ->expectsOutputToContain("1 migration hasn't run. Run php artisan migrate --force.")
            ->assertExitCode(1);
    }

    public function test_pending_migrations_still_count_when_a_package_adds_a_migration_path(): void
    {
        $this->goodInstall();
        DB::table('migrations')->orderByDesc('id')->limit(1)->delete();
        app('migrator')->path(sys_get_temp_dir());

        $this->artisan('ssvs:check-install')
            ->expectsOutputToContain("1 migration hasn't run. Run php artisan migrate --force.")
            ->assertExitCode(1);
    }

    public function test_no_organization_fails(): void
    {
        $this->goodInstall();
        Organization::query()->delete();

        $this->artisan('ssvs:check-install')
            ->expectsOutputToContain("There's no organization yet. Run php artisan ssvs:setup.")
            ->assertExitCode(1);
    }

    public function test_a_silent_scheduler_fails_the_check(): void
    {
        $this->goodInstall();
        $this->travelTo(CarbonImmutable::now()->addMinutes(6));

        $this->artisan('ssvs:check-install')
            ->expectsOutputToContain(' UTC. Run systemctl enable --now ssvs-scheduler.timer.')
            ->assertExitCode(1);
    }

    public function test_a_scheduler_that_never_ran_fails_the_check(): void
    {
        $this->goodInstall();
        cache()->forget('ssvs:scheduler-heartbeat');

        $this->artisan('ssvs:check-install')
            ->expectsOutputToContain('The scheduler has never run. Run systemctl enable --now ssvs-scheduler.timer.')
            ->assertExitCode(1);
    }

    public function test_a_star_proxy_is_a_note_not_a_failure(): void
    {
        $this->goodInstall();
        config(['app.trusted_proxies' => '*']);

        $this->artisan('ssvs:check-install')
            ->expectsOutputToContain('NOTE TRUSTED_PROXIES is "*", which is only safe when the app can be reached only through your proxy.')
            ->assertExitCode(0);
    }

    public function test_the_heartbeat_is_scheduled_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'ssvs:heartbeat');

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }
}
