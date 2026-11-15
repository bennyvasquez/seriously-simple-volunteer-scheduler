<?php

namespace Tests;

use Database\Seeders\ExampleOrganizationSeeder;
use Facebook\WebDriver\Chrome\ChromeOptions;
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
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The login and link request limits live in the file cache, which outlives a test run, so runs close together would be refused.
        Cache::flush();
    }

    /**
     * Dusk reuses its browsers from one test to the next, so a login left behind by a test, even one that failed partway, would leak into the next. Deleting the cookies ends every session.
     */
    protected function tearDown(): void
    {
        foreach (static::$browsers as $browser) {
            $browser->driver->manage()->deleteAllCookies();
        }

        parent::tearDown();
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
