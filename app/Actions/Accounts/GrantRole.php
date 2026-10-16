<?php

namespace App\Actions\Accounts;

use App\Enums\Role;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * Gives someone a role. A null actor is the system, used only by first-run setup.
 */
class GrantRole
{
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

        $assignment = $user->roles()->firstOrCreate(['role' => $role->value, 'area_id' => $area?->id]);

        if ($assignment->wasRecentlyCreated) {
            ChangeLogEntry::record($actor, 'role.granted', $user, ['role' => $role->value, 'area_id' => $area?->id]);
        }

        return $assignment;
    }
}
