<?php

namespace App\Http\Controllers;

use App\Actions\Accounts\ConfirmEmailChange;
use App\Actions\Accounts\IssueLoginLink;
use App\Actions\Accounts\RequestEmailChange;
use App\Enums\LoginLinkPurpose;
use App\Http\Controllers\Auth\LoginLinkController;
use App\Models\LoginLink;
use App\Models\User;
use App\Notifications\ConfirmItsYouNotification;
use App\Support\Input;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/**
 * Changing your own email address (spec section 13). First you confirm it's you: with your password, or with a link to your current address if you have no password. Then we email a link to the new address, and the change happens when it's opened.
 */
class EmailChangeController extends Controller
{
    /** Set when someone without a password confirms it's them with an emailed link. */
    public const IDENTITY_CONFIRMED_AT = 'account.email.identity_confirmed_at';

    public function edit(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $this->recentlyConfirmed($request)) {
            if ($user->password !== null) {
                return redirect()->guest(route('password.confirm'));
            }

            return view('account.email', ['step' => 'identity', 'user' => $user]);
        }

        return view('account.email', ['step' => 'form', 'user' => $user, 'needsCode' => $user->hasEnabledTwoFactorAuthentication()]);
    }

    /** Emails a link to the current address of someone without a password. People with a password confirm with it instead. */
    public function sendIdentityLink(Request $request, IssueLoginLink $issueLoginLink): RedirectResponse
    {
        $user = $request->user();

        abort_if($user->password !== null, 403);

        $url = $issueLoginLink->handle($user, LoginLinkPurpose::ConfirmIdentity);
        defer(fn () => $user->notify(new ConfirmItsYouNotification($url)));

        return redirect()->route('account.email.edit')->with('status', 'We\'ve emailed a link to '.$user->email.'. Open it to keep going.');
    }

    /** The page the identity link opens. It never uses the link: email security scanners open links too. */
    public function showIdentity(string $token): View
    {
        $link = $this->usableIdentityLink(LoginLink::findUsable($token, LoginLinkPurpose::ConfirmIdentity));

        return view('account.email', ['step' => $link === null ? 'expired' : 'identity-link']);
    }

    public function confirmIdentity(Request $request, string $token): View|RedirectResponse
    {
        $link = $this->usableIdentityLink(LoginLink::claim($token, LoginLinkPurpose::ConfirmIdentity));

        if ($link === null) {
            return view('account.email', ['step' => 'expired']);
        }

        if (! $link->user->is($request->user())) {
            if (Auth::check()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
            }

            Auth::login($link->user);
            $request->session()->regenerate();
        }

        // Its own key, not Laravel's auth.password_confirmed_at: this link stands in for a password only on the email change screens, never on other password-protected routes.
        $request->session()->put(self::IDENTITY_CONFIRMED_AT, time());

        // Opening this link proves control of the inbox just as a sign-in link does.
        LoginLinkController::rememberLinkLogin($request, $link->user);

        return redirect()->route('account.email.edit');
    }

    public function update(Request $request, RequestEmailChange $requestEmailChange): RedirectResponse
    {
        $user = $request->user();

        if (! $this->recentlyConfirmed($request)) {
            return $user->password !== null ? redirect()->guest(route('password.confirm')) : redirect()->route('account.email.edit');
        }

        $needsCode = $user->hasEnabledTwoFactorAuthentication();

        // The address's format is checked first, so a typo doesn't use up a valid app code. The format says nothing about who has an account.
        $email = Str::lower($request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            ...($needsCode ? ['code' => ['required', 'string']] : []),
        ])['email']);

        // A rule about the address itself, so refusing it says nothing about anyone's account.
        if (User::isUnusableEmail($email)) {
            throw ValidationException::withMessages(['email' => 'That email address can\'t be used.']);
        }

        // Your own address is refused out loud: it says nothing about anyone else's account.
        if ($email === $user->email) {
            throw ValidationException::withMessages(['email' => 'That\'s already your address.']);
        }

        // The app code is checked before the address is looked up or anything is sent.
        if ($needsCode) {
            $valid = app(TwoFactorAuthenticationProvider::class)->verify(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), Input::string($request, 'code'));

            if (! $valid) {
                throw ValidationException::withMessages(['code' => 'That code didn\'t work. Check your app and try the newest code.']);
            }
        }

        $requestEmailChange->handle($user, $email);

        return redirect()->route('account.show')->with('status', 'We\'ve sent a link to '.$email.'. Your current address keeps working until you open it.');
    }

    /** The page the link to the new address opens. It never uses the link: email security scanners open links too. */
    public function showConfirm(string $token): View
    {
        $link = LoginLink::findUsable($token, LoginLinkPurpose::EmailChange);

        return view('account.email', $link === null ? ['step' => 'expired'] : ['step' => 'confirm-link', 'newEmail' => $link->email]);
    }

    public function confirm(Request $request, string $token, ConfirmEmailChange $confirmEmailChange): View
    {
        $link = LoginLink::findUsable($token, LoginLinkPurpose::EmailChange);

        // Keep this device signed in only when it's the person's own session.
        $sessionId = $link !== null && $link->user->is($request->user()) ? $request->session()->getId() : null;
        $guard = Auth::guard('web');
        $wasRemembered = $sessionId !== null && ($guard->viaRemember() || $request->cookies->has($guard->getRecallerName()));

        if ($link === null || ! $confirmEmailChange->handle($token, $sessionId)) {
            return view('account.email', ['step' => 'expired']);
        }

        // Signing out everywhere cycled the remember-me token, so a device of theirs that was remembered gets a new cookie and stays remembered, the same as after a password change. Other devices' cookies no longer match. Read fresh, so the cookie carries the new token.
        if ($wasRemembered) {
            $guard->login($link->user->fresh(), true);
        }

        // On a shared device where someone else is logged in, the page mustn't link to that person's account.
        $someoneElse = $request->user() !== null && $sessionId === null;

        return view('account.email', ['step' => 'changed', 'newEmail' => $link->email, 'someoneElse' => $someoneElse]);
    }

    /** Same rule as Laravel's password.confirm middleware: confirmed within the password timeout. A confirmation by identity link counts only for people without a password. */
    private function recentlyConfirmed(Request $request): bool
    {
        $session = $request->session();
        $confirmedAt = (int) $session->get('auth.password_confirmed_at', 0);

        if ($request->user()->password === null) {
            $confirmedAt = max($confirmedAt, (int) $session->get(self::IDENTITY_CONFIRMED_AT, 0));
        }

        return time() - $confirmedAt < (int) config('auth.password_timeout', 10800);
    }

    /** Identity links are only for people without a password (and so without a second step). If that changed after the link was sent, the link doesn't work. */
    private function usableIdentityLink(?LoginLink $link): ?LoginLink
    {
        return $link !== null && $link->user->password === null && ! $link->user->hasEnabledTwoFactorAuthentication() ? $link : null;
    }
}
