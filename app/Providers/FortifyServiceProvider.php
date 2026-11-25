<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Http\Controllers\Auth\TwoFactorEmailCodeController;
use App\Http\Responses\FailedTwoFactorLoginResponse;
use App\Http\Responses\NeutralPasswordResetLinkResponse;
use App\Models\User;
use App\Notifications\TwoFactorAttemptsNotification;
use App\Support\EmailAddress;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse as FailedTwoFactorLoginResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    private static ?string $dummyHash = null;

    public function register(): void
    {
        // Asking for a password reset answers the same way for every address (spec: no response reveals whether an account exists).
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, NeutralPasswordResetLinkResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, NeutralPasswordResetLinkResponse::class);
        // A wrong code goes back to the form it came from (app or recovery code), not to whichever form the screen would pick by default.
        $this->app->singleton(FailedTwoFactorLoginResponseContract::class, FailedTwoFactorLoginResponse::class);
    }

    public function boot(): void
    {
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        // Deactivated accounts, people who aren't invited yet and accounts without a password can't log in with a password.
        Fortify::authenticateUsing(function (Request $request) {
            // A new password attempt cancels any pending login started earlier in this session (including an email-link one). If the attempt fails, nothing stays pending, so a failed attempt can't leave a link-started login in a state that allows an emailed code.
            $request->session()->forget(['login.id', 'login.remember', 'login.via', 'login.email_code_sent']);

            $user = User::query()->where('email', EmailAddress::normalize((string) $request->input(Fortify::username())))->first();

            $usable = $user !== null && $user->canSignIn() && $user->password !== null;

            // Every path does one hash check, so response time doesn't reveal whether the address has a usable account.
            $matches = Hash::check((string) $request->input('password'), $usable ? $user->password : self::dummyHash());

            if (! $usable || ! $matches) {
                return null;
            }

            // Fortify stores login.id and login.remember after this callback returns and leaves login.via alone. The emailed two-factor code is only allowed when this marker says the pending login came from a password.
            $request->session()->put('login.via', 'password');

            return $user;
        });

        Fortify::loginView(fn () => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));

        Fortify::twoFactorChallengeView(function (Request $request) {
            $session = $request->session();
            $pendingUser = User::query()->find($session->get('login.id'));
            $justReset = $pendingUser?->password_reset_at !== null;
            $canEmailCode = $pendingUser !== null && $session->get('login.via') === 'password' && ! $justReset;

            // A failed recovery code comes back without the query string, so the error says which form to show.
            $mode = match (true) {
                $request->boolean('recovery') || $request->session()->get('errors')?->has('recovery_code') => 'recovery',
                $canEmailCode && ! $request->boolean('app') && $session->get('login.email_code_sent') && Cache::has(TwoFactorEmailCodeController::cacheKey($pendingUser)) => 'email',
                default => 'app',
            };

            return view('auth.two-factor-challenge', compact('pendingUser', 'canEmailCode', 'justReset', 'mode'));
        });

        RateLimiter::for('login', function (Request $request) {
            $username = $request->input(Fortify::username());
            $throttleKey = EmailAddress::normalize(is_string($username) ? $username : '').'|'.$request->ip();

            return Limit::perMinute(5)->by($throttleKey);
        });

        // The app code, recovery code and emailed code routes share these limits. The hourly and daily ones also tell the person, once a day, that someone is trying codes on their account. There may be no pending login (the id is null); the limits still apply, but there's no one to tell.
        RateLimiter::for('two-factor', function (Request $request) {
            $id = $request->session()->get('login.id');
            $paused = function (Request $request, array $headers) use ($id) {
                if (User::query()->find($id) !== null && Cache::add('two-factor-alerted|'.$id, true, now()->addDay())) {
                    // The refusal is a 429, and deferred work is skipped after an error response unless it says it always runs.
                    defer(fn () => User::query()->find($id)?->notify(new TwoFactorAttemptsNotification), always: true);
                }

                throw new ThrottleRequestsException('Too Many Attempts.', null, $headers);
            };

            return [
                Limit::perMinute(5)->by($id),
                Limit::perHour(20)->by('two-factor-hour|'.$id)->response($paused),
                Limit::perDay(50)->by('two-factor-day|'.$id)->response($paused),
            ];
        });
    }

    /** A hash nobody's password matches, computed once, used to keep login timing the same when there's no usable account. */
    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make(Str::random(40));
    }
}
