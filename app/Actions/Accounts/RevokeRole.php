<?php

namespace App\Actions\Accounts;

use App\Enums\Role;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Takes a role away. Role checks read the database on every request, so access ends immediately (spec section 13, "People leaving").
 */
class RevokeRole
{
    public function handle(?User $actor, User $user, Role $role, ?Area $area = null): void
    {
        if ($actor !== null && ! $actor->canGrant($role)) {
            throw new AuthorizationException('You can\'t change that role.');
        }

        DB::transaction(function () use ($actor, $user, $role, $area) {
            // Lock the person's row so role changes for them run one at a time: the unique index doesn't cover a NULL area_id.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $deleted = $user->roles()
                ->where('role', $role->value)
                ->where('area_id', $area?->id)
                ->delete();

            if ($deleted > 0) {
                ChangeLogEntry::record($actor, 'role.revoked', $user, ['role' => $role->value, 'area_id' => $area?->id]);
            }
        });
    }
}
