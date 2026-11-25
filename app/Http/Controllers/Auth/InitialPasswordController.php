<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\ChangeLogEntry;
use App\Notifications\PasswordAddedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;

/**
 * Lets someone who joined by invitation, and so has no password, choose one. Changing an existing password goes through Fortify, which asks for the current one.
 */
class InitialPasswordController extends Controller
{
    use PasswordValidationRules;

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        // A second tab or a resubmitted form. Changing a password asks for the current one, on the account page. Someone still in setup would only be sent on from there, losing the message, so they go straight back to the setup screen.
        if ($user->password !== null) {
            if ($user->requiresTwoFactor() && ! $user->hasEnabledTwoFactorAuthentication()) {
                return redirect()->route('account.security')->with('status', 'You already have a password, so the next step is the authenticator app.');
            }

            return redirect()->route('account.show')->with('status', 'You already have a password. You can change it below.');
        }

        // Adding a first password needs proof that the person controls the inbox: an emailed-link sign-in in the last few minutes. People in two-factor setup are exempt, because setup is how they get their first password.
        if (! $user->requiresTwoFactor() && ! $this->signedInByLinkRecently($request)) {
            throw ValidationException::withMessages([
                'password' => 'To add a password, sign in again with an emailed link, then add it within '.LoginLinkController::RECENT_MINUTES.' minutes.',
            ]);
        }

        $request->validate(['password' => $this->passwordRules()]);

        DB::transaction(function () use ($user, $request) {
            $user->forceFill(['password' => Hash::make($request->input('password'))]);
            $changes = ChangeLogEntry::changesOf($user);
            $user->save();

            ChangeLogEntry::record($user, 'user.password_set', $user, changes: $changes);
        });

        defer(fn () => $user->notify(new PasswordAddedNotification));

        if ($user->requiresTwoFactor()) {
            // Enabling two-factor sits behind password confirmation, and they just typed the password, so skip asking again. Everyone else confirms later in the usual way.
            $request->session()->passwordConfirmed();

            app(EnableTwoFactorAuthentication::class)($user);
        }

        // Someone who doesn't need two-factor is finished, so send them back to their account page.
        if (! $user->requiresTwoFactor()) {
            return redirect()->route('account.show')->with('status', 'Your password is saved.');
        }

        return redirect()->route('account.security')->with('status', 'Your password is set.');
    }

    private function signedInByLinkRecently(Request $request): bool
    {
        $at = $request->session()->get('auth.link_login_at');

        return is_int($at) && $at >= now()->subMinutes(LoginLinkController::RECENT_MINUTES)->getTimestamp();
    }
}
