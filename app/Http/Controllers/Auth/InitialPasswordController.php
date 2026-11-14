<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\ChangeLogEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

        $request->validate(['password' => $this->passwordRules()]);

        DB::transaction(function () use ($user, $request) {
            $user->forceFill(['password' => Hash::make($request->input('password'))]);
            $changes = ChangeLogEntry::changesOf($user);
            $user->save();

            ChangeLogEntry::record($user, 'user.password_set', $user, changes: $changes);
        });

        // Enabling two-factor sits behind password confirmation, and they just typed the password, so skip asking again.
        $request->session()->passwordConfirmed();

        if ($user->requiresTwoFactor()) {
            app(EnableTwoFactorAuthentication::class)($user);
        }

        // Someone who doesn't need two-factor is finished, so send them back to their account page.
        if (! $user->requiresTwoFactor()) {
            return redirect()->route('account.show')->with('status', 'Your password is saved.');
        }

        return redirect()->route('account.security')->with('status', 'Your password is set.');
    }
}
