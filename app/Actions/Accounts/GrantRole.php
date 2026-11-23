<?php

namespace App\Actions\Accounts;

use App\Enums\Role;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Gives someone a role. A null actor is the system, used only by first-run setup. Holding a role clears you (decision 4 of the clearance plan), so when the person isn't cleared yet, they're cleared through ClearPerson in the same transaction, with `via` "role.granted". Taking the role away later leaves them cleared.
 */
class GrantRole
{
    public function __construct(private ClearPerson $clearPerson) {}

    public function handle(?User $actor, User $user, Role $role, ?Area $area = null): RoleAssignment
    {
        if ($role === Role::AreaLead && $area === null) {
            throw new InvalidArgumentException('An area lead has to lead an area.');
        }

        if ($role !== Role::AreaLead && $area !== null) {
            throw new InvalidArgumentException('Only area leads belong to an area.');
        }

        if ($actor !== null && ! $actor->canGrant($role)) {
            throw new AuthorizationException('You can\'t give people that role.');
        }

        return DB::transaction(function () use ($actor, $user, $role, $area) {
            // Lock the person's row so role changes for them run one at a time: the unique index doesn't cover a NULL area_id.
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $assignment = $user->roles()->firstOrCreate(['role' => $role->value, 'area_id' => $area?->id]);

            if ($assignment->wasRecentlyCreated) {
                ChangeLogEntry::record($actor, 'role.granted', $user, ['role' => $role->value, 'area_id' => $area?->id]);
            }

            if ($this->clearPerson->clearLocked($actor, $locked, 'role.granted')) {
                // So the caller's copy of the person doesn't read as not cleared.
                $user->forceFill(['cleared_at' => $locked->cleared_at])->syncOriginalAttribute('cleared_at');
            }

            return $assignment;
        });
    }
}
