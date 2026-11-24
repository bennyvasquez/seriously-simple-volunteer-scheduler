<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\ChangeLogEntry;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Dusk\DuskServiceProvider;
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
        // Dusk adds routes such as /_dusk/login/{userId}, which log anyone in as anyone with no password, in every environment but production. composer.json keeps package discovery from loading it, so it's registered here only for the browser tests, which run with APP_ENV=testing. A real server installs without dev packages, so the class isn't there at all.
        if ($this->app->environment('testing') && class_exists(DuskServiceProvider::class)) {
            $this->app->register(DuskServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Long enough to resist guessing; no breached-password lookup, because that calls an outside service on every change.
        Password::defaults(fn () => Password::min(10));

        // Only admins change organization settings (spec section 6).
        // Every active person can read the schedule; changing it needs a role (spec section 6).
        Gate::define('view-schedule', fn (User $user) => $user->isActive());
        Gate::define('view-change-log', fn (User $user) => $user->isActive() && $user->isCoordinator());
        Gate::define('manage-shadowing', fn (User $user) => $user->isActive() && $user->isCoordinator());
        Gate::define('manage-settings', fn (User $user) => $user->isActive() && $user->hasRole(Role::Admin));

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

            $request = request();

            if (! $request->hasSession()) {
                return;
            }

            $session = $request->session();

            // Someone who still has to set up two-factor (after an admin reset it, say) just typed their password, so "Set up the app" doesn't ask for it again. Only them: for everyone else a login doesn't count as confirming the password.
            if ($request->routeIs('login.store') && $session->get('login.via') === 'password' && $event->user->requiresTwoFactor() && ! $event->user->hasEnabledTwoFactorAuthentication()) {
                $session->passwordConfirmed();
            }

            // The marker only matters while a login is pending.
            $session->forget('login.via');
        });

        Event::listen(TwoFactorAuthenticationConfirmed::class, function ($event) {
            ChangeLogEntry::record($event->user, 'two_factor.enabled', $event->user);

            // A newly connected app has just proved itself, like an app code at login, so a pending email reset no longer blocks the emailed code. Unlogged, the same as the login below.
            if ($event->user->password_reset_at !== null) {
                $event->user->forceFill(['password_reset_at' => null])->save();
            }
        });
        Event::listen(TwoFactorAuthenticationDisabled::class, fn ($event) => ChangeLogEntry::record($event->user, 'two_factor.disabled', $event->user));
        Event::listen(RecoveryCodesGenerated::class, fn ($event) => ChangeLogEntry::record($event->user, 'two_factor.recovery_codes_regenerated', $event->user));
        // Only the user: the event also carries the code that was used, which must never reach the log.
        Event::listen(RecoveryCodeReplaced::class, fn ($event) => ChangeLogEntry::record($event->user, 'two_factor.recovery_code_used', $event->user));
        // Fortify fires this only when its challenge accepts an authenticator or recovery code, so the emailed code can be offered again after a reset.
        Event::listen(ValidTwoFactorAuthenticationCodeProvided::class, fn ($event) => $event->user->forceFill(['password_reset_at' => null])->save());
    }
}
