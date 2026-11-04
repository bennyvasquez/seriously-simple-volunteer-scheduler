<?php

namespace App\Actions\Accounts;

use App\Enums\LoginLinkPurpose;
use App\Models\ChangeLogEntry;
use App\Models\User;
use App\Notifications\InvitationNotification;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Invites someone, or sends their invitation again. The first time sets invited_at; every time issues a new invitation link, which cancels the old one, and emails it after the response. Refuses someone who has accepted, been turned off or been removed.
 */
class InvitePerson
{
    public function __construct(private IssueLoginLink $issueLoginLink) {}

    public function handle(User $actor, User $person): void
    {
        if (! $actor->isCoordinator()) {
            throw new AuthorizationException('Only coordinators and admins can invite people.');
        }

        $url = DB::transaction(function () use ($actor, $person) {
            $locked = User::query()->whereKey($person->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_removed_person) {
                throw new DomainException('That person has been removed.');
            }

            if (! $locked->isActive()) {
                throw new DomainException($locked->firstName().' has been turned off and can\'t be invited.');
            }

            if ($locked->email_verified_at !== null) {
                throw new DomainException($locked->firstName().' has already accepted their invitation.');
            }

            $firstTime = $locked->invited_at === null;
            $changes = [];

            if ($firstTime) {
                $locked->invited_at = now();
                $changes = ChangeLogEntry::changesOf($locked);
                $locked->save();
            }

            $url = $this->issueLoginLink->handle($locked, LoginLinkPurpose::Invitation);

            ChangeLogEntry::record($actor, $firstTime ? 'user.invited' : 'user.invitation_resent', $locked, changes: $changes);

            $person->setRawAttributes($locked->getAttributes(), true);

            return $url;
        });

        // After the response, so a mail failure never shows an error for a change that was saved.
        defer(fn () => $person->notify(new InvitationNotification($url, $actor->name)));
    }
}
