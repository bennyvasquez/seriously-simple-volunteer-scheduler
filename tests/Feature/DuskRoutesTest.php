<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\Facades\Route;
use Laravel\Dusk\DuskServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Dusk's routes (/_dusk/login/{userId} and friends) log anyone in as anyone with no password, so they may exist only for the browser tests, which run with APP_ENV=testing. PHPUnit itself runs as testing, so the other environments are tried on a fresh application that only runs AppServiceProvider::register(), which is where the decision is made.
 */
class DuskRoutesTest extends TestCase
{
    public function test_package_discovery_doesnt_load_dusk(): void
    {
        $this->assertNotContains(DuskServiceProvider::class, $this->app->make(PackageManifest::class)->providers());
    }

    public static function environmentsWithoutDusk(): array
    {
        return [
            'local' => ['local'],
            'production' => ['production'],
            'staging' => ['staging'],
        ];
    }

    #[DataProvider('environmentsWithoutDusk')]
    public function test_dusk_isnt_registered_outside_the_testing_environment(string $environment): void
    {
        $this->assertArrayNotHasKey(DuskServiceProvider::class, $this->registerAppServiceProviderIn($environment)->getLoadedProviders());
    }

    public function test_dusk_is_registered_in_the_testing_environment(): void
    {
        $this->assertArrayHasKey(DuskServiceProvider::class, $this->registerAppServiceProviderIn('testing')->getLoadedProviders());
    }

    public function test_the_testing_app_has_the_dusk_login_route_the_browser_tests_use(): void
    {
        $this->assertTrue(Route::has('dusk.login'));
    }

    private function registerAppServiceProviderIn(string $environment): Application
    {
        $app = new Application($this->app->basePath());
        $app['env'] = $environment;

        (new AppServiceProvider($app))->register();

        return $app;
    }
}
