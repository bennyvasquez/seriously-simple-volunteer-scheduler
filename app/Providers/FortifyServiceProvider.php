<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Http\Controllers\Auth\TwoFactorEmailCodeController;
use App\Http\Responses\FailedTwoFactorLoginResponse;
use App\Http\Responses\NeutralPasswordResetLinkResponse;
use App\Models\User;
use App\Support\EmailAddress;
use App\Support\Input;
use App\Support\TwoFactorAlert;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse as FailedTwoFactorLoginResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
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
        // A failed attempt takes the same fixed time (config auth.timebox_duration) whatever the address, so the time it takes can't reveal whether the address has a usable account. A successful one returns as soon as it's done.
        Fortify::authenticateUsing(fn (Request $request) => $this->app->make(Timebox::class)->call(function (Timebox $timebox) use ($request) {
            // A new password attempt cancels any pending login started earlier in this session (including an email-link one). If the attempt fails, nothing stays pending, so a failed attempt can't leave a link-started login in a state that allows an emailed code.
            $request->session()->forget(['login.id', 'login.remember', 'login.via', 'login.email_code_sent']);

            $user = User::query()->where('email', EmailAddress::normalize(Input::string($request, Fortify::username())))->first();

            $usable = $user !== null && $user->canSignIn() && $user->password !== null;

            // Every path does one hash check, so the work done doesn't depend on whether the address has a usable account. The hash for the other paths is made once and kept in the cache, so making it adds time only on the first such login after the cache is cleared, and the timebox around this callback evens that out along with what's left.
            $matches = Hash::check(Input::string($request, 'password'), $usable ? $user->password : self::dummyHash());

            if (! $usable || ! $matches) {
                return null;
            }

            // Fortify stores login.id and login.remember after this callback returns and leaves login.via alone. The emailed two-factor code is only allowed when this marker says the pending login came from a password.
            $request->session()->put('login.via', 'password');

            $timebox->returnEarly();

            return $user;
        }, (int) config('auth.timebox_duration')));

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

        // Five attempts a minute for an address from one IP address, and 20 failed attempts an hour for an address from anywhere. The hourly limit counts only failures, so signing in correctly never brings a person closer to it, and it never looks the address up, so it reads the same whether or not there's an account. Email links and password resets still work while it's reached.
        RateLimiter::for('login', function (Request $request) {
            $username = $request->input(Fortify::username());
            $address = EmailAddress::normalize(is_string($username) ? $username : '');

            return array_filter([
                Limit::perMinute(5)->by($address.'|'.$request->ip()),
                // A login that worked has signed the person in or left a login waiting for their two-factor code. The password callback clears any waiting login before it checks, so a waiting one here is this request's. A blank address or one that isn't plain has no hourly limit (the form refuses it anyway), so those attempts don't all share one count, the same as the login link and reset link limits.
                $address === '' || ! EmailAddress::isPlain($address) ? null : Limit::perHour(20)->by('login-address|'.$address)->after(fn () => ! Auth::guard(config('fortify.guard'))->check() && ! $request->session()->has('login.id')),
            ]);
        });

        // The app code, recovery code and emailed code routes share these limits. The hourly and daily ones, and ten wrong codes in a day, also tell the person, once a day, that someone is trying codes on their account. There may be no pending login (the id is null); the limits still apply, but there's no one to tell.
        RateLimiter::for('two-factor', function (Request $request) {
            $id = $request->session()->get('login.id');
            $paused = function (Request $request, array $headers) use ($id) {
                TwoFactorAlert::send($id);

                throw new ThrottleRequestsException('Too Many Attempts.', null, $headers);
            };

            return [
                Limit::perMinute(5)->by($id),
                Limit::perHour(20)->by('two-factor-hour|'.$id)->response($paused),
                Limit::perDay(50)->by('two-factor-day|'.$id)->response($paused),
            ];
        });
    }

    /** The cache key for the hash below. It names the hashing driver and the bcrypt cost, so changing either makes a new hash that costs the same to check as a real password. */
    public static function dummyHashKey(): string
    {
        return 'auth.dummy-hash.'.config('hashing.driver').'.'.config('hashing.bcrypt.rounds');
    }

    /** A hash of a random value nobody knows, checked when there's no usable account so the login does the same work either way. It's made once and kept in the cache, not made again for each request. */
    private static function dummyHash(): string
    {
        return Cache::rememberForever(self::dummyHashKey(), fn () => Hash::make(Str::random(40)));
    }
}
