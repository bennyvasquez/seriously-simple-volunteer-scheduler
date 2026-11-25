<?php

namespace App\Actions\Fortify;

use App\Actions\Accounts\SignOutEverywhere;
use App\Models\ChangeLogEntry;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    public function __construct(private SignOutEverywhere $signOutEverywhere) {}

    /**
     * Validate and update the user's password.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => $this->passwordRules(),
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->validateWithBag('updatePassword');

        $request = request();
        $guard = Auth::guard('web');
        $wasRemembered = $guard->viaRemember() || $request->cookies->has($guard->getRecallerName());

        DB::transaction(function () use ($user, $input, $request) {
            $user->forceFill(['password' => Hash::make($input['password'])]);
            $changes = ChangeLogEntry::changesOf($user);
            $user->save();

            ChangeLogEntry::record($user, 'user.password_changed', $user, changes: $changes);

            // This device stays signed in; every other session and remember-me cookie ends.
            $this->signOutEverywhere->handle($user, $request->hasSession() ? $request->session()->getId() : null);
        });

        // Signing out everywhere cycled the remember-me token, so a device that was remembered gets a new cookie and stays remembered. Other devices' cookies no longer match.
        if ($wasRemembered) {
            $guard->login($user, true);
        }

        // A new session id, so the old one can't be reused.
        if ($request->hasSession()) {
            $request->session()->regenerate(true);
        }
    }
}
