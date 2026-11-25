<?php

namespace Tests;

use Database\Seeders\ExampleOrganizationSeeder;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Exception\PhpWebDriverExceptionInterface;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;

/**
 * Browser tests run in the dusk container against Chromium in the browser container (see compose.yaml and DEVELOPMENT.md). Every test starts from the seeded example organization, never a blank database.
 */
abstract class DuskTestCase extends BaseTestCase
{
    use DatabaseTruncation;

    protected bool $seed = true;

    protected string $seeder = ExampleOrganizationSeeder::class;

    /**
     * Prepare for Dusk test execution. ChromeDriver runs in its own container when DUSK_DRIVER_URL is set, so there's nothing to start here.
     */
    #[BeforeClass]
    public static function prepare(): void
    {
        if (! static::runningInSail() && ! static::driverUrl()) {
            static::startChromeDriver(['--port=9515']);
        }

        // Console logs from earlier runs would be read as this run's. Each test's own logs are checked in tearDown().
        foreach (glob(__DIR__.'/Browser/console/*') ?: [] as $log) {
            @unlink($log);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The login and link request limits live in the file cache, which outlives a test run, so runs close together would be refused.
        Cache::flush();
    }

    /**
     * Dusk reuses its browsers from one test to the next, so a login left behind by a test, even one that failed partway, would leak into the next. Deleting the cookies ends every session. A browser whose driver has died is skipped, so its error can't hide the test's real failure, and the parent teardown always runs.
     */
    protected function tearDown(): void
    {
        try {
            foreach (static::$browsers as $browser) {
                try {
                    $browser->driver->manage()->deleteAllCookies();
                } catch (PhpWebDriverExceptionInterface) {
                    // Covers both WebDriver errors and an unreachable driver. The test that broke the browser has already failed, and that failure is the one worth reading.
                }
            }
        } finally {
            parent::tearDown();
        }

        $this->assertNoContentSecurityPolicyViolations();
    }

    /**
     * Dusk saves the browser console of any test that logged something to tests/Browser/console, in files named after the test. A page that breaks the Content-Security-Policy header (an inline script or style, a file from another site) logs a line saying so, and the page misbehaves quietly, so every test checks its own logs for it, and one violation fails only the test that hit it.
     */
    private function assertNoContentSecurityPolicyViolations(): void
    {
        $directory = __DIR__.'/Browser/console';
        $prefix = $this->getCallerName().'-';

        foreach (scandir($directory) ?: [] as $log) {
            if (! str_starts_with($log, $prefix)) {
                continue;
            }

            $text = (string) file_get_contents($directory.'/'.$log);
            $this->assertStringNotContainsString('Content Security Policy', $text, $log.' has a Content-Security-Policy violation');
            $this->assertStringNotContainsString('Content-Security-Policy', $text, $log.' has a Content-Security-Policy violation');
        }
    }

    /**
     * Create the RemoteWebDriver instance.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(collect([
            $this->shouldStartMaximized() ? '--start-maximized' : '--window-size=1920,1080',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
            // The app is served over plain HTTP in the container, and browsers keep some features, such as the clipboard, to secure origins.
            '--unsafely-treat-insecure-origin-as-secure='.config('app.url'),
        ])->unless($this->hasHeadlessDisabled(), function (Collection $items) {
            return $items->merge([
                '--disable-gpu',
                '--headless=new',
            ]);
        })->all());

        return RemoteWebDriver::create(
            static::driverUrl() ?: 'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(
                ChromeOptions::CAPABILITY, $options
            )
        );
    }

    private static function driverUrl(): ?string
    {
        return $_ENV['DUSK_DRIVER_URL'] ?? $_SERVER['DUSK_DRIVER_URL'] ?? (getenv('DUSK_DRIVER_URL') ?: null);
    }
}
