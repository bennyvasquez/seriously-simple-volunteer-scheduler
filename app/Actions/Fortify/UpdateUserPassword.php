<?php

namespace App\Actions\Fortify;

use App\Actions\Accounts\SignOutEverywhere;
use App\Models\ChangeLogEntry;
use App\Models\User;
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

        DB::transaction(function () use ($user, $input) {
            $user->forceFill(['password' => Hash::make($input['password'])]);
            $changes = ChangeLogEntry::changesOf($user);
            $user->save();

            ChangeLogEntry::record($user, 'user.password_changed', $user, changes: $changes);

            // This device stays signed in; every other session and remember-me cookie ends.
            $this->signOutEverywhere->handle($user, request()->session()->getId());
        });

        // A new session id, so the old one can't be reused.
        request()->session()->regenerate(true);
    }
}
