<?php

namespace App\Actions\Accounts;

use App\Actions\Scheduling\ReleaseShifts;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Notifications\ClearedNotification;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Clears someone for regular shifts (spec section 9): once cleared, they can take shifts and get cover requests. Clearing is logged as `user.cleared`, about the person, with `cleared_at` old and new in `changes` and how they were cleared in `details.via`: `role.granted` (holding a role clears you), `import`, `migration` (they already held a role), `example_seed` (the example seeder's regulars) or `person_page` (a coordinator pressed "Clear for regular shifts"). Only handle() emails the person.
 */
class ClearPerson
{
    /**
     * A coordinator clears someone from the person page, with an optional note for the change log (trimmed, up to 500 characters, kept only in `changes`). Locks the person's row, so two coordinators pressing it at once log it once and the second hears "{First name} is already cleared." Someone turned off is refused. After the response the person gets "You're cleared for regular shifts", if they can sign in. The refusals are written to be shown on screen. `$person` reads as cleared afterwards.
     */
    public function handle(User $actor, User $person, ?string $note): void
    {
        Gate::forUser($actor)->authorize('clear', $person);

        $note = ReleaseShifts::cleanNote($note);

        $locked = DB::transaction(function () use ($actor, $person, $note) {
            $locked = User::query()->whereKey($person->id)->lockForUpdate()->firstOrFail();

            if ($locked->isCleared()) {
                throw new DomainException($locked->firstName().' is already cleared.');
            }

            if (! $locked->isActive()) {
                throw new DomainException($locked->firstName().' has been turned off. Turn them back on first.');
            }

            $this->clear($actor, $locked, 'person_page', $note, $person);

            return $locked;
        });

        if ($locked->canSignIn()) {
            $email = new ClearedNotification($actor, Organization::current());
            defer(fn () => $locked->notify($email));
        }
    }

    /**
     * For a caller that already holds this person's row lock inside its own transaction. Clears them and logs it when they aren't cleared yet, and returns whether it did. Sends no email: a new role or an import isn't news to the person. Pass the caller's own copy of the person as `$callersCopy` so it reads as cleared too.
     */
    public function clearLocked(?User $actor, User $lockedPerson, string $via, ?User $callersCopy = null): bool
    {
        if ($lockedPerson->isCleared()) {
            return false;
        }

        $this->clear($actor, $lockedPerson, $via, null, $callersCopy);

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

    /** The one place clearing is written: sets `cleared_at` on the locked row, logs it with the note (if any) in `changes`, and brings the caller's copy into line without marking it dirty. */
    private function clear(?User $actor, User $lockedPerson, string $via, ?string $note, ?User $callersCopy): void
    {
        $lockedPerson->forceFill(['cleared_at' => now()]);
        $changes = ChangeLogEntry::changesOf($lockedPerson);
        $lockedPerson->save();

        if ($note !== null) {
            $changes['note'] = ['old' => null, 'new' => $note];
        }

        ChangeLogEntry::record($actor, 'user.cleared', $lockedPerson, ['via' => $via], $changes);

        $callersCopy?->forceFill(['cleared_at' => $lockedPerson->cleared_at])->syncOriginalAttribute('cleared_at');
    }
}
