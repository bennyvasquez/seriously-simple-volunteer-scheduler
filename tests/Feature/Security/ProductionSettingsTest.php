<?php

namespace Tests\Feature\Security;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class ProductionSettingsTest extends TestCase
{
    private function bootAs(string $environment): void
    {
        $this->app['env'] = $environment;

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_debug_is_turned_off_in_production(): void
    {
        config(['app.debug' => true]);
        Log::spy();

        $this->bootAs('production');

        $this->assertFalse(config('app.debug'));
        Log::shouldHaveReceived('critical')->once()->withArgs(fn (string $message) => str_contains($message, 'APP_DEBUG'));
    }

    public function test_debug_stays_on_in_local(): void
    {
        config(['app.debug' => true]);
        Log::spy();

        $this->bootAs('local');

        $this->assertTrue(config('app.debug'));
        Log::shouldNotHaveReceived('critical');
    }

    public function test_production_with_debug_already_off_logs_nothing(): void
    {
        config(['app.debug' => false]);
        Log::spy();

        $this->bootAs('production');

        Log::shouldNotHaveReceived('critical');
    }

    public function test_the_log_mailer_refuses_to_send_in_production(): void
    {
        config(['mail.default' => 'log']);
        $this->bootAs('production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MAIL_MAILER');

        Mail::mailer('log')->raw('Example login link', fn ($message) => $message->to('someone@example.org')->subject('Example'));
    }

    public function test_other_mailers_still_send_in_production(): void
    {
        $this->bootAs('production');

        Mail::mailer('array')->raw('Example login link', fn ($message) => $message->to('someone@example.org')->subject('Example'));

        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_the_log_mailer_still_works_outside_production(): void
    {
        $this->bootAs('testing');

        Mail::mailer('log')->raw('Example message', fn ($message) => $message->to('someone@example.org')->subject('Example'));

        $this->expectNotToPerformAssertions();
    }

    public function test_the_failover_list_has_no_log_mailer(): void
    {
        $this->assertNotContains('log', config('mail.mailers.failover.mailers'));
    }

    public function test_emails_still_send_under_tests(): void
    {
        $this->assertSame('array', config('mail.default'));

        Mail::raw('Example login link', fn ($message) => $message->to('someone@example.org')->subject('Example'));

        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
    }
}
