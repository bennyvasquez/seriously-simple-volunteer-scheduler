<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Http\Responses\NeutralPasswordResetLinkResponse;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
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

        // Placeholder screens until the screens plan replaces them.
        Fortify::loginView(fn () => view('auth.pending', ['screen' => 'Log in']));
        Fortify::twoFactorChallengeView(fn () => view('auth.pending', ['screen' => 'Enter your code']));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.pending', ['screen' => 'Reset your password']));
        Fortify::resetPasswordView(fn () => view('auth.pending', ['screen' => 'Choose a new password']));
        Fortify::confirmPasswordView(fn () => view('auth.pending', ['screen' => 'Confirm your password']));

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
