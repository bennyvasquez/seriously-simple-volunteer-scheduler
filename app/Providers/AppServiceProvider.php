<?php

namespace App\Providers;

use App\Models\ChangeLogEntry;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

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
        RateLimiter::for('email-change', fn (Request $request) => Limit::perMinutes(10, 5)->by('email-change|'.$request->user()?->id));
        RateLimiter::for('email-change-identity', fn (Request $request) => Limit::perMinutes(10, 5)->by('email-change-identity|'.$request->user()?->id));
        RateLimiter::for('two-factor-email', fn (Request $request) => Limit::perMinutes(10, 3)->by('two-factor-email|'.$request->session()->get('login.id')));

        // A query-builder update, so signing in fires no model events and writes no change log entry. Login also fires when a remember-me cookie signs someone back in.
        Event::listen(Login::class, function (Login $event) {
            $now = now();
            User::query()->whereKey($event->user->getAuthIdentifier())->update(['last_login_at' => $now, 'last_seen_at' => $now]);

            // Keeps RecordLastSeen from writing again in the same request.
            foreach (['last_login_at', 'last_seen_at'] as $column) {
                $event->user->setAttribute($column, $now);
                $event->user->syncOriginalAttribute($column);
            }
        });

        Event::listen(TwoFactorAuthenticationConfirmed::class, fn ($event) => ChangeLogEntry::record($event->user, 'two_factor.enabled', $event->user));
        Event::listen(TwoFactorAuthenticationDisabled::class, fn ($event) => ChangeLogEntry::record($event->user, 'two_factor.disabled', $event->user));
        Event::listen(RecoveryCodesGenerated::class, fn ($event) => ChangeLogEntry::record($event->user, 'two_factor.recovery_codes_regenerated', $event->user));
        // Only the user: the event also carries the code that was used, which must never reach the log.
        Event::listen(RecoveryCodeReplaced::class, fn ($event) => ChangeLogEntry::record($event->user, 'two_factor.recovery_code_used', $event->user));
        // Fortify fires this only when its challenge accepts an authenticator or recovery code, so the emailed code can be offered again after a reset.
        Event::listen(ValidTwoFactorAuthenticationCodeProvided::class, fn ($event) => $event->user->forceFill(['password_reset_at' => null])->save());
    }
}
