<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Http\Controllers\Auth\TwoFactorEmailCodeController;
use App\Http\Responses\FailedTwoFactorLoginResponse;
use App\Http\Responses\NeutralPasswordResetLinkResponse;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
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

        // Deactivated accounts and accounts without a password can't log in with a password.
        Fortify::authenticateUsing(function (Request $request) {
            // A new password attempt cancels any pending login started earlier in this session (including an email-link one). If the attempt fails, nothing stays pending, so a failed attempt can't leave a link-started login in a state that allows an emailed code.
            $request->session()->forget(['login.id', 'login.remember', 'login.via']);

            $user = User::query()->where('email', Str::lower($request->input(Fortify::username())))->first();

            $usable = $user !== null && $user->isActive() && $user->password !== null;

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
                $canEmailCode && ! $request->boolean('app') && Cache::has(TwoFactorEmailCodeController::cacheKey($pendingUser)) => 'email',
                default => 'app',
            };

            return view('auth.two-factor-challenge', compact('pendingUser', 'canEmailCode', 'justReset', 'mode'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }

    /** A hash nobody's password matches, computed once, used to keep login timing the same when there's no usable account. */
    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make(Str::random(40));
    }
}
