<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ChangeLogEntry;
use App\Models\User;
use App\Notifications\TwoFactorCodeNotification;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * The fallback when someone can't reach their authenticator app: a code by email. Only after a password login, because after an email-link login the inbox has already been used once and a second email isn't a second factor (spec section 13).
 */
class TwoFactorEmailCodeController extends Controller
{
    private const LIFETIME_MINUTES = 10;

    private const MAX_TRIES = 5;

    public function send(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = now()->addMinutes(self::LIFETIME_MINUTES);

        Cache::put(self::cacheKey($user), ['hash' => hash('sha256', $code), 'tries' => 0, 'expires_at' => $expiresAt->getTimestamp()], $expiresAt);
        $user->notify(new TwoFactorCodeNotification($code));

        return back()->with('status', 'We emailed you a 6-digit code. It works for 10 minutes.');
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);
        $request->validate(['code' => ['required', 'string']]);

        $key = self::cacheKey($user);
        $entry = Cache::get($key);

        if ($entry === null) {
            return back()->withErrors(['code' => 'That code has expired. Ask for a new one.']);
        }

        if (! hash_equals($entry['hash'], hash('sha256', trim($request->input('code'))))) {
            $entry['tries']++;

            if ($entry['tries'] >= self::MAX_TRIES) {
                Cache::forget($key);

                return back()->withErrors(['code' => 'That code didn\'t match, and it\'s now used up. Ask for a new one.']);
            }

            Cache::put($key, $entry, now()->setTimestamp($entry['expires_at']));

            return back()->withErrors(['code' => 'That code didn\'t match. Check the email and try again.']);
        }

        Cache::forget($key);
        $remember = (bool) $request->session()->pull('login.remember', false);
        $request->session()->forget(['login.id', 'login.via']);

        Auth::login($user, $remember);
        $request->session()->regenerate();
        ChangeLogEntry::record($user, 'two_factor.email_code_used', $user);

        return redirect()->intended(config('fortify.home'));
    }

    private function pendingUser(Request $request): User
    {
        $id = $request->session()->get('login.id');
        $user = $id === null ? null : User::query()->find($id);

        if ($user === null || ! $user->isActive()) {
            throw new HttpResponseException(redirect()->route('login')->withErrors(['email' => 'Log in again to get a code.']));
        }

        if ($request->session()->get('login.via') !== 'password') {
            $this->refuse('An emailed code can\'t be used after logging in with an email link. Use your authenticator app or a recovery code, or log in with your password instead.');
        }

        // A reset by email used only the inbox, so the inbox can't be the second factor too until the next app or recovery code login.
        if ($user->password_reset_at !== null) {
            $this->refuse('Your password was just reset by email, so use your authenticator app or a recovery code this time.');
        }

        return $user;
    }

    /** Sends the person back to the code screen with the reason, instead of an error page. */
    private function refuse(string $message): never
    {
        throw new HttpResponseException(redirect()->route('two-factor.login')->withErrors(['email_code' => $message]));
    }

    /** The cache key for a person's pending emailed code. The code screen also reads it to know whether to show the email form. */
    public static function cacheKey(User $user): string
    {
        return "two-factor-email:{$user->id}";
    }
}
