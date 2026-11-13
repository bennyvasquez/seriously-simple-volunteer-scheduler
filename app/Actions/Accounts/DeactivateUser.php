<?php

namespace App\Actions\Accounts;

use App\Actions\Scheduling\ReleaseFutureSchedule;
use App\Enums\Role;
use App\Models\ChangeLogEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns an account off when someone stops volunteering (spec section 13, "People leaving"). Their history stays; they can't log in by any route. The account is turned off and committed first, so anyone putting them on a shift or a slot from then on is refused, and then ReleaseFutureSchedule removes them from every future shift and ends their regular slots, without emailing them.
 *
 * Someone already turned off isn't turned off again (nothing is logged for it), but still gets the release, so people turned off before this change can be tidied by turning them off again. Returns the release's counts.
 */
class DeactivateUser
{
    public function __construct(private readonly ReleaseFutureSchedule $releaseFutureSchedule) {}

    /** @return array{shifts: int, regulars: int} */
    public function handle(User $actor, User $user): array
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
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($locked->deactivated_at !== null) {
                $user->setRawAttributes($locked->getAttributes(), true);

                return;
            }

            $user->forceFill(['deactivated_at' => now(), 'remember_token' => Str::random(60)]);
            $changes = ChangeLogEntry::changesOf($user);
            $user->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->loginLinks()->whereNull('used_at')->delete();

            ChangeLogEntry::record($actor, 'user.deactivated', $user, changes: $changes);
        });

        return $this->releaseFutureSchedule->handle($actor, $user, 'user.deactivated');
    }
}
