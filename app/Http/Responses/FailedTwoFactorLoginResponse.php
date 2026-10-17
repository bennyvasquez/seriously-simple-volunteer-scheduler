<?php

namespace App\Http\Responses;

use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse as FailedTwoFactorLoginResponseContract;

/**
 * Fortify's answer to a wrong authenticator or recovery code, except that it sends the person back to the form they used. Without the query string, someone who had asked for an emailed code would land on the email form after a wrong app code.
 */
class FailedTwoFactorLoginResponse implements FailedTwoFactorLoginResponseContract
{
    public function toResponse($request)
    {
        [$key, $message, $mode] = $request->filled('recovery_code')
            ? ['recovery_code', __('The provided two factor recovery code was invalid.'), ['recovery' => 1]]
            : ['code', __('The provided two factor authentication code was invalid.'), ['app' => 1]];

        if ($request->wantsJson()) {
            throw ValidationException::withMessages([
                $key => [$message],
            ]);
        }

        return redirect()->route('two-factor.login', $mode)->withErrors([$key => $message]);
    }
}
