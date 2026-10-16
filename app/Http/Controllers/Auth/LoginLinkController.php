<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Accounts\ConsumeLoginLink;
use App\Actions\Accounts\IssueLoginLink;
use App\Enums\LoginLinkPurpose;
use App\Http\Controllers\Controller;
use App\Models\ChangeLogEntry;
use App\Models\User;
use App\Notifications\LoginLinkNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LoginLinkController extends Controller
{
    /** Emails a login link. The answer is the same whether or not the address has an account. */
    public function request(Request $request, IssueLoginLink $issueLoginLink): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $user = User::query()->where('email', Str::lower($request->input('email')))->first();

        if ($user !== null && $user->isActive()) {
            // After the response is sent: the answer and how long it takes must not depend on whether the address has an account, and a mail server failure must not show up only for real accounts.
            defer(fn () => $user->notify(new LoginLinkNotification($issueLoginLink->handle($user, LoginLinkPurpose::SignIn))));
        }

        return back()->with('status', 'If that address has an account, we\'ve emailed a link. It works for 15 minutes.');
    }

    /** The page an emailed link opens. It never logs anyone in: email security scanners open links too. */
    public function show(string $token): View
    {
        return view('auth.pending', ['screen' => 'Log in with your link']);
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
            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            ChangeLogEntry::record($user, 'user.accepted_invitation', $user);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            // Hand off to Fortify's two-factor challenge. Remember this was a link login: an emailed code won't count as a second factor.
            $request->session()->put(['login.id' => $user->id, 'login.remember' => false, 'login.via' => 'link']);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(config('fortify.home'));
    }
}
