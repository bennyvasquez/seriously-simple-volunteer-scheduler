<?php

namespace App\Actions\Accounts;

use App\Models\ChangeLogEntry;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Clears someone for regular shifts (spec section 9): once cleared, they can take shifts and get cover requests. Clearing is logged as `user.cleared`, about the person, with `cleared_at` old and new in `changes` and how they were cleared in `details.via`: `role.granted` (holding a role clears you), `import`, `migration` (they already held a role, or the example seeder) or `person_page`. Clearing sends no email from here.
 */
class ClearPerson
{
    /**
     * For a caller that already holds this person's row lock inside its own transaction. Clears them and logs it when they aren't cleared yet, and returns whether it did.
     */
    public function clearLocked(?User $actor, User $lockedPerson, string $via): bool
    {
        if ($lockedPerson->isCleared()) {
            return false;
        }

        $lockedPerson->forceFill(['cleared_at' => now()]);
        $changes = ChangeLogEntry::changesOf($lockedPerson);
        $lockedPerson->save();

        ChangeLogEntry::record($actor, 'user.cleared', $lockedPerson, ['via' => $via], $changes);

        return true;
    }

    /**
     * Clears everyone who holds a role and isn't cleared yet, as the system, in one transaction, locking each person's row in id order. Used by the migration that added clearance. Returns how many people it cleared.
     */
    public function clearRoleHolders(): int
    {
        return DB::transaction(function () {
            $ids = User::query()
                ->whereNull('cleared_at')
                ->whereIn('id', RoleAssignment::query()->select('user_id'))
                ->orderBy('id')
                ->pluck('id');

            $cleared = 0;

            foreach ($ids as $id) {
                $person = User::query()->whereKey($id)->lockForUpdate()->firstOrFail();

                if ($this->clearLocked(null, $person, 'migration')) {
                    $cleared++;
                }
            }

            return $cleared;
        });
    }
}
