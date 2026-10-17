<?php

namespace App\Actions\Accounts;

use App\Enums\LoginLinkPurpose;
use App\Models\ChangeLogEntry;
use App\Models\LoginLink;
use App\Notifications\EmailChangedNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Finishes an email address change when the link sent to the new address is opened. Returns false, changing nothing, for a link that can't be used or when another account has taken the address since. Signs the person out on every other device, keeping `$currentSessionId` (pass it only when that session is the person's own), and tells the old address.
 */
class ConfirmEmailChange
{
    public function __construct(private SignOutEverywhere $signOutEverywhere) {}

    public function handle(string $token, ?string $currentSessionId): bool
    {
        try {
            $change = DB::transaction(function () use ($token, $currentSessionId) {
                $link = LoginLink::claim($token, LoginLinkPurpose::EmailChange);

                if ($link === null) {
                    return null;
                }

                $user = $link->user;

                // Someone else may have taken the address since the link was sent.
                if (DB::table('users')->where('email', $link->email)->where('id', '!=', $user->id)->exists()) {
                    return null;
                }

                $oldEmail = $user->email;
                // Opening the link proves the person controls the new address.
                $user->forceFill(['email' => $link->email, 'email_verified_at' => now()]);
                $changes = ChangeLogEntry::changesOf($user);
                $user->save();

                ChangeLogEntry::record($user, 'user.email_changed', $user, changes: $changes);
                $this->signOutEverywhere->handle($user, $currentSessionId);

                return ['old' => $oldEmail, 'new' => $link->email];
            });
        } catch (UniqueConstraintViolationException) {
            // Another account took the address at the same moment; the transaction rolled back.
            return false;
        }

        if ($change === null) {
            return false;
        }

        // The person's address has changed, so this goes to the old one directly. After the response is sent, so a mail server failure can't turn a finished change into an error page.
        defer(fn () => Notification::route('mail', $change['old'])->notify(new EmailChangedNotification($change['new'])));

        return true;
    }
}
