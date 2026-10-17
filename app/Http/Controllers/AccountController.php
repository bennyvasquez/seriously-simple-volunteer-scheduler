<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

/**
 * The screens staff finish before they can use the app: a first password, the authenticator app, and the recovery codes shown once.
 */
class AccountController extends Controller
{
    public function security(Request $request): View|Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->password === null) {
            return view('account.security', ['step' => 1, 'user' => $user, 'roleSentence' => $this->roleSentence($user)]);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            // Fortify flashes this status once, right after the code is confirmed. That is the only time the codes are shown.
            if (session('status') === 'two-factor-authentication-confirmed') {
                return $this->recoveryCodesView($user);
            }

            return redirect()->route('home');
        }

        $setupKey = $user->two_factor_secret ? Fortify::currentEncrypter()->decrypt($user->two_factor_secret) : null;
        $view = view('account.security', ['step' => 2, 'user' => $user, 'setupKey' => $setupKey]);

        // The setup key and QR code are the secret itself, so the browser mustn't keep a copy.
        return $setupKey === null ? $view : response($view)->header('Cache-Control', 'no-store');
    }

    /** Where anyone manages how they log in. */
    public function show(Request $request): View|Response
    {
        $user = $request->user();
        $status = session('status');

        // Fortify flashes this status once, right after it makes new codes. That is the only time they are shown.
        if ($status === 'recovery-codes-generated' && $user->hasEnabledTwoFactorAuthentication()) {
            return $this->recoveryCodesView($user, "Here are your new recovery codes. The old ones don't work anymore.", 'Login and security');
        }

        return view('account.show', [
            'user' => $user,
            'hasPassword' => $user->password !== null,
            'isStaff' => $user->requiresTwoFactor(),
            // Fortify flashes its own keyword when a password changes; other messages are already words.
            'status' => $status === 'password-updated' ? 'Your password is changed.' : $status,
        ]);
    }

    /** The codes are shown once, so the browser mustn't keep a copy of the page either. $header names the screen it was reached from. */
    private function recoveryCodesView($user, ?string $message = null, string $header = 'Secure your account'): Response
    {
        $name = Organization::query()->value('name') ?? config('app.name');

        return response(view('account.recovery-codes', [
            'codes' => $user->recoveryCodes(),
            'filename' => Str::slug($name).'-recovery-codes.txt',
            'message' => $message,
            'header' => $header,
        ]))->header('Cache-Control', 'no-store');
    }

    /** The highest role wins: admin over coordinator over area lead. */
    private function roleSentence($user): string
    {
        return match (true) {
            $user->hasRole(Role::Admin) => 'As an admin',
            $user->hasRole(Role::Coordinator) => 'As a coordinator',
            default => 'As an area lead',
        };
    }
}
