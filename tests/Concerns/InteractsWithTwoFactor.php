<?php

namespace Tests\Concerns;

use App\Models\User;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

trait InteractsWithTwoFactor
{
    /** Turns on confirmed authenticator-app two-factor for a user and returns the secret. */
    protected function enableTwoFactor(User $user): string
    {
        $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-code-one', 'recovery-code-two'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $secret;
    }

    protected function currentCode(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }
}
