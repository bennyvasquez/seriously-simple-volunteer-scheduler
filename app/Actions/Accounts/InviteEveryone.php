<?php

namespace App\Actions\Accounts;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Invites everyone who is active and not invited yet. Each person is invited in their own transaction that re-reads invited_at under the row lock, so pressing the button twice, or two coordinators pressing it together, sends each person one invitation. Returns how many were invited.
 */
class InviteEveryone
{
    public function __construct(private InvitePerson $invitePerson) {}

    public function handle(User $actor): int
    {
        if (! $actor->isCoordinator()) {
            throw new AuthorizationException('Only coordinators and admins can invite people.');
        }

        $invited = 0;

        foreach (self::waitingToBeInvited()->orderBy('id')->pluck('id') as $id) {
            DB::transaction(function () use ($actor, $id, &$invited) {
                $person = User::query()->whereKey($id)->lockForUpdate()->first();

                if ($person === null || $person->invited_at !== null || ! $person->isActive() || $person->is_removed_person) {
                    return;
                }

                $this->invitePerson->handle($actor, $person);
                $invited++;
            });
        }

        return $invited;
    }

    /** @return Builder<User> Active people who haven't been invited yet. */
    public static function waitingToBeInvited(): Builder
    {
        return User::query()->people()->whereNull('deactivated_at')->whereNull('invited_at');
    }
}
