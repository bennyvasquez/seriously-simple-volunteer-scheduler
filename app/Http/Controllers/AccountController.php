<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

/**
 * The screens staff finish before they can use the app: a first password, the authenticator app, and the recovery codes shown once.
 */
class AccountController extends Controller
{
    public function security(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->password === null) {
            return view('account.security', ['step' => 1, 'user' => $user, 'roleSentence' => $this->roleSentence($user)]);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            // Fortify flashes this status once, right after the code is confirmed. That is the only time the codes are shown.
            if (session('status') === 'two-factor-authentication-confirmed') {
                $name = Organization::query()->value('name') ?? config('app.name');

                return view('account.recovery-codes', [
                    'codes' => $user->recoveryCodes(),
                    'filename' => Str::slug($name).'-recovery-codes.txt',
                ]);
            }

            return redirect()->route('home');
        }

        return view('account.security', [
            'step' => 2,
            'user' => $user,
            'setupKey' => $user->two_factor_secret ? Fortify::currentEncrypter()->decrypt($user->two_factor_secret) : null,
        ]);
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
