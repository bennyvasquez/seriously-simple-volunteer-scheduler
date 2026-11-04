<?php

namespace App\Actions\Accounts;

use App\Models\ChangeLogEntry;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds someone to the list without inviting them. They have no password, are "not invited yet", and get no email of any kind until InvitePerson runs. A null actor means the system, used only by setup.
 */
class CreatePerson
{
    public function handle(?User $actor, string $name, string $email, ?string $memberId = null): User
    {
        if ($actor !== null && ! $actor->isCoordinator()) {
            throw new AuthorizationException('Only coordinators and admins can add people.');
        }

        $email = Str::lower(trim($email));

        // A backstop for the invite form and the import, which refuse it first.
        if (User::isUnusableEmail($email)) {
            throw new DomainException('That email address can\'t be used.');
        }

        if (User::query()->where('email', $email)->exists()) {
            throw new DomainException('Someone with that email address already has an account.');
        }

        return DB::transaction(function () use ($actor, $name, $email, $memberId) {
            $person = new User(['name' => trim($name), 'email' => $email, 'password' => null]);
            $person->forceFill(['member_id' => $memberId]);
            $changes = ChangeLogEntry::changesOf($person);
            $person->save();

            ChangeLogEntry::record($actor, 'user.created', $person, changes: $changes);

            return $person;
        });
    }
}
