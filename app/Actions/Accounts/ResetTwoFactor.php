<?php

namespace App\Actions\Accounts;

use App\Enums\Role;
use App\Models\ChangeLogEntry;
use App\Models\User;
use App\Notifications\TwoFactorResetNotification;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * An admin resets someone's two-factor after checking in person or by phone that it's really them (spec section 13). The authenticator app and recovery codes stop working, the person is logged out everywhere and emailed, and they set up a new app on their next password login.
 */
class ResetTwoFactor
{
    public function __construct(private SignOutEverywhere $signOutEverywhere) {}

    public function handle(User $admin, User $user): void
    {
        if (! $admin->isActive() || ! $admin->hasRole(Role::Admin)) {
            throw new AuthorizationException('Only an admin can reset two-factor.');
        }

        if ($admin->is($user)) {
            throw new DomainException('Ask another admin to reset your own two-factor.');
        }

        if ($user->two_factor_secret === null) {
            throw new DomainException($user->firstName()." doesn't have an authenticator app connected.");
        }

        DB::transaction(function () use ($admin, $user) {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ]);
            $changes = ChangeLogEntry::changesOf($user);
            $user->save();

            ChangeLogEntry::record($admin, 'two_factor.reset', $user, changes: $changes);
            $this->signOutEverywhere->handle($user, actor: $admin);
        });

        // After the transaction commits, so a rolled-back reset never sends the email, and after the response, so a mail server failure doesn't show the admin an error for a reset that already happened.
        defer(fn () => $user->notify(new TwoFactorResetNotification($admin)));
    }
}
