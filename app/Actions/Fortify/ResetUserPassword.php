<?php

namespace App\Actions\Fortify;

use App\Models\ChangeLogEntry;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        // A reset uses only the inbox, so the emailed two-factor code stays off until the next app or recovery code login (spec section 13).
        DB::transaction(function () use ($user, $input) {
            $user->forceFill([
                'password' => Hash::make($input['password']),
                'password_reset_at' => now(),
            ]);
            $changes = ChangeLogEntry::changesOf($user);
            $user->save();

            ChangeLogEntry::record($user, 'user.password_reset', $user, changes: $changes);
        });

        // After the response: the new password is already saved, so a mail server failure is logged instead of shown to the person.
        defer(fn () => $user->notify(new PasswordChangedNotification));
    }
}
