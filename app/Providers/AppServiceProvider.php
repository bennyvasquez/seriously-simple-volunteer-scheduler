<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Long enough to resist guessing; no breached-password lookup, because that calls an outside service on every change.
        Password::defaults(fn () => Password::min(10));

        RateLimiter::for('login-link', fn (Request $request) => Limit::perMinutes(10, 3)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('login-link-use', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
