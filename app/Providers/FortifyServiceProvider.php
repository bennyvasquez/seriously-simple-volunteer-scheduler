<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        // Deactivated accounts and accounts without a password can't log in with a password.
        Fortify::authenticateUsing(function (Request $request) {
            // A password login never counts as an email-link login, even if one was started earlier in this session.
            $request->session()->forget('login.via');

            $user = User::query()->where('email', Str::lower($request->input(Fortify::username())))->first();

            if ($user !== null && $user->isActive() && $user->password !== null && Hash::check($request->input('password'), $user->password)) {
                return $user;
            }

            return null;
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
}
