<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\LaravelMailLines;
use App\Notifications\PlainText;
use App\Support\EmailAddress;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\Translator;
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
     * Makes every link the app builds, emailed ones included, use the site's own address from APP_URL instead of the address a request was sent to. It reads config(), not env(), so it still works once the configuration is cached.
     */
    public function useSiteAddress(): void
    {
        $siteAddress = (string) config('app.url');

        URL::forceRootUrl($siteAddress);

        if (str_starts_with($siteAddress, 'https://')) {
            URL::forceScheme('https');
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->useSiteAddress();

        // Laravel's mail layout puts the app name in each email's header, under "Regards," and in the footer, and the sender's name comes from the mail settings. Before an email goes out, both become the organization's name from Settings, so volunteers see who's writing to them rather than "SSVS". The address stays the one in MAIL_FROM_ADDRESS.
        Event::listen(NotificationSending::class, function (NotificationSending $event) {
            $name = $event->channel === 'mail' ? Organization::query()->value('name') : null;

            if ($name === null || $name === '') {
                return;
            }

            // Laravel's mail views only HTML-escape the app name, and the HTML part then goes through Markdown, so it goes in already escaped for both (PlainText::line), the way every other value in an email does, and reads exactly as typed.
            config(['app.name' => PlainText::line($name), 'mail.from.name' => $name]);
            Mail::alwaysFrom((string) config('mail.from.address'), $name);
        });

        // Once the email is sent, or its sending fails (Laravel fires NotificationFailed for any exception while sending), the app name is plain text again for anything else that reads it, including later jobs in a long-running queue worker.
        Event::listen([NotificationSent::class, NotificationFailed::class], function (NotificationSent|NotificationFailed $event) {
            if ($event->channel === 'mail' && config('app.name') instanceof Htmlable) {
                config(['app.name' => config('mail.from.name')]);
            }
        });

        // The four lines Laravel's mail layout adds ("Hello!", "Regards,", the line under each button and "All rights reserved.") read the organization's edits, if any, when an email is rendered. Nothing is copied from Laravel's views.
        $this->callAfterResolving('translator', fn (Translator $translator) => LaravelMailLines::register($translator));

        // Long enough to resist guessing; no breached-password lookup, because that calls an outside service on every change.
        Password::defaults(fn () => Password::min(10));

        // Every active person can read the schedule; changing it needs a role (spec section 6).
        Gate::define('view-schedule', fn (User $user) => $user->isActive());
        Gate::define('view-change-log', fn (User $user) => $user->isActive() && $user->isCoordinator());
        Gate::define('manage-shadowing', fn (User $user) => $user->isActive() && $user->isCoordinator());
        // Changing the wording of the emails reaches every volunteer's inbox, so it's for active coordinators and admins, who sign in with two-factor (spec section 6).
        Gate::define('manage-emails', fn (User $user) => $user->isActive() && $user->isCoordinator());
        // Only admins change organization settings (spec section 6).
        Gate::define('manage-settings', fn (User $user) => $user->isActive() && $user->hasRole(Role::Admin));

        RateLimiter::for('login-link', fn (Request $request) => Limit::perMinutes(10, 3)->by(EmailAddress::normalize(is_string($request->input('email')) ? $request->input('email') : '').'|'.$request->ip()));
        RateLimiter::for('login-link-use', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('email-change', fn (Request $request) => Limit::perMinutes(10, 5)->by('email-change|'.$request->user()?->id));
        RateLimiter::for('email-change-identity', fn (Request $request) => Limit::perMinutes(10, 5)->by('email-change-identity|'.$request->user()?->id));
        // Saving, resetting and previewing email wording, per person, so a stolen session can't hammer the editor.
        RateLimiter::for('email-editor', fn (Request $request) => Limit::perMinute(30)->by('email-editor|'.$request->user()?->id));
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
