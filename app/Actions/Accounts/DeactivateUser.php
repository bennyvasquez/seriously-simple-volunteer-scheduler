<?php

namespace App\Actions\Accounts;

use App\Enums\Role;
use App\Models\ChangeLogEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns an account off when someone stops volunteering (spec section 13, "People leaving"). Their history stays; they can't log in by any route. Releasing their future shifts comes with the release flow.
 */
class DeactivateUser
{
    public function handle(User $actor, User $user): void
    {
        if (! $actor->isCoordinator()) {
            throw new AuthorizationException('Only coordinators and admins can deactivate accounts.');
        }

        if ($actor->is($user)) {
            throw new AuthorizationException('You can\'t deactivate your own account. Ask another coordinator or admin.');
        }

        if ($user->hasRole(Role::Admin) && ! $actor->hasRole(Role::Admin)) {
            throw new AuthorizationException('Only an admin can deactivate another admin.');
        }

        DB::transaction(function () use ($actor, $user) {
            $user->forceFill(['deactivated_at' => now(), 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->loginLinks()->whereNull('used_at')->delete();

            ChangeLogEntry::record($actor, 'user.deactivated', $user);
        });
    }
}
