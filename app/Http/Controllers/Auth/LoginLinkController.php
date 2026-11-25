<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Accounts\ConsumeLoginLink;
use App\Actions\Accounts\IssueLoginLink;
use App\Enums\LoginLinkPurpose;
use App\Http\Controllers\Controller;
use App\Models\ChangeLogEntry;
use App\Models\LoginLink;
use App\Models\User;
use App\Notifications\LoginLinkNotification;
use App\Support\EmailAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LoginLinkController extends Controller
{
    /** How many minutes after signing in with an emailed link a first password can still be added. */
    public const RECENT_MINUTES = 15;

    /** The session key that remembers who last signed in by an emailed link, and when. */
    public const LINK_LOGIN_KEY = 'auth.link_login';

    /** Records that this person just proved they control their inbox by opening an emailed link. */
    public static function rememberLinkLogin(Request $request, User $user): void
    {
        $request->session()->put(self::LINK_LOGIN_KEY, ['user' => $user->id, 'at' => now()->getTimestamp()]);
    }

    /** Whether the signed-in person opened an emailed link in the last few minutes. */
    public static function signedInByLinkRecently(Request $request): bool
    {
        $login = $request->session()->get(self::LINK_LOGIN_KEY);

        return is_array($login)
            && $request->user() !== null
            && ($login['user'] ?? null) === $request->user()->id
            && is_int($login['at'] ?? null)
            && $login['at'] >= now()->subMinutes(self::RECENT_MINUTES)->getTimestamp();
    }

    /** Emails a login link. The answer is the same whether or not the address has an account. */
    public function request(Request $request, IssueLoginLink $issueLoginLink): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $user = User::query()->where('email', Str::lower($request->input('email')))->first();

        // Read now: the deferred closure runs after the response, when the session may already be saved.
        $destination = LoginLink::safeDestination($request->session()->get('url.intended'));

        if ($user !== null && $user->canSignIn()) {
            // After the response is sent: the answer and how long it takes must not depend on whether the address has an account, and a mail server failure must not show up only for real accounts.
            defer(fn () => $user->notify(new LoginLinkNotification($issueLoginLink->handle($user, LoginLinkPurpose::SignIn, destination: $destination))));
        }

        return self::sentResponse($request);
    }

    /** The answer to asking for a link, the same for every address. The daily limit for an address gives this answer too, without sending anything. */
    public static function sentResponse(Request $request): RedirectResponse
    {
        // login_link_sent tells the login screen to show "Check your email". Other flows also flash a plain status to that screen.
        return back()->withInput(EmailAddress::normalizeInput($request))
            ->with('status', 'If that address has an account, we\'ve emailed a link. It works for 15 minutes.')
            ->with('login_link_sent', true);
    }

    /** The page an emailed link opens. It never logs anyone in: email security scanners open links too. */
    public function show(string $token): View
    {
        $link = LoginLink::findUsable($token, ...LoginLinkPurpose::forLoggingIn());

        $inviter = null;
        if ($link?->purpose === LoginLinkPurpose::Invitation) {
            $inviter = ChangeLogEntry::query()->about($link->user)->ofAction('user.invited')->first()?->actor_name;
        }

        // Someone holding a used or expired invitation gets the invitation's own explanation. The token is theirs, so this tells them nothing new.
        $expiredInvitation = $link === null && LoginLink::query()->where('token_hash', hash('sha256', $token))->where('purpose', LoginLinkPurpose::Invitation)->exists();

        return view('auth.link', ['link' => $link, 'inviter' => $inviter, 'expiredInvitation' => $expiredInvitation]);
    }

    public function consume(Request $request, string $token, ConsumeLoginLink $consumeLoginLink): RedirectResponse
    {
        $link = $consumeLoginLink->handle($token);

        if ($link === null) {
            return redirect()->route('login')->withErrors([
                'email' => 'That link has expired or was already used. Ask for a new one.',
            ]);
        }

        $user = $link->user;

        if ($link->purpose === LoginLinkPurpose::Invitation) {
            DB::transaction(function () use ($user) {
                $changes = [];

                if ($user->email_verified_at === null) {
                    $user->forceFill(['email_verified_at' => now()]);
                    $changes = ChangeLogEntry::changesOf($user);
                    $user->save();
                }

                ChangeLogEntry::record($user, 'user.accepted_invitation', $user, changes: $changes);
            });
        }

        // The destination lives on the link, not only in the session, so it survives opening the link in a different browser. Both the normal redirect and Fortify's two-factor redirect read url.intended.
        if ($link->destination !== null) {
            $request->session()->put('url.intended', url($link->destination));
        }

        // A link login replaces any login left pending in this browser, such as someone else's password login abandoned at the code screen, so none of its markers carry over.
        $request->session()->forget(['login.id', 'login.remember', 'login.via', 'login.email_code_sent', self::LINK_LOGIN_KEY]);

        if ($user->hasEnabledTwoFactorAuthentication()) {
            // Hand off to Fortify's two-factor challenge. Remember this was a link login: an emailed code won't count as a second factor.
            $request->session()->put(['login.id' => $user->id, 'login.remember' => $request->boolean('remember'), 'login.via' => 'link']);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        self::rememberLinkLogin($request, $user);

        return redirect()->intended(config('fortify.home'));
    }
}
