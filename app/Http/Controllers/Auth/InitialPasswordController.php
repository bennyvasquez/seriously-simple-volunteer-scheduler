<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\ChangeLogEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Lets someone who joined by invitation, and so has no password, choose one. Changing an existing password goes through Fortify, which asks for the current one.
 */
class InitialPasswordController extends Controller
{
    use PasswordValidationRules;

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_if($user->password !== null, 403, 'You already have a password. Change it from your account settings.');

        $request->validate(['password' => $this->passwordRules()]);

        $user->forceFill(['password' => Hash::make($request->input('password'))])->save();
        ChangeLogEntry::record($user, 'user.password_set', $user);

        return redirect()->route('account.security')->with('status', 'Your password is set.');
    }
}
