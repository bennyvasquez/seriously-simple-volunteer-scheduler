<?php

namespace App\Actions\Accounts;

use App\Enums\LoginLinkPurpose;
use App\Models\ChangeLogEntry;
use App\Models\User;
use App\Notifications\InvitationNotification;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates an account for someone and emails them a link to accept (spec section 13, "Invite-only"). They start without a password and not cleared.
 */
class InviteVolunteer
{
    public function __construct(private IssueLoginLink $issueLoginLink) {}

    public function handle(User $inviter, string $name, string $email): User
    {
        if (! $inviter->isCoordinator()) {
            throw new AuthorizationException('Only coordinators and admins can invite volunteers.');
        }

        $email = Str::lower(trim($email));

        if (User::query()->where('email', $email)->exists()) {
            throw new DomainException('Someone with that email address already has an account.');
        }

        return DB::transaction(function () use ($inviter, $name, $email) {
            $user = new User(['name' => trim($name), 'email' => $email, 'password' => null]);
            $changes = ChangeLogEntry::changesOf($user);
            $user->save();

            ChangeLogEntry::record($inviter, 'user.invited', $user, changes: $changes);

            $url = $this->issueLoginLink->handle($user, LoginLinkPurpose::Invitation);
            $user->notify(new InvitationNotification($url, $inviter->name));

            return $user;
        });
    }
}
