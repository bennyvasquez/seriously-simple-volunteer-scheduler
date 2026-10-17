<?php

namespace App\Actions\Accounts;

use App\Enums\LoginLinkPurpose;
use App\Models\User;
use App\Notifications\EmailChangeConfirmationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Starts an email address change (spec section 13): emails a link to the new address, and nothing changes until it's opened. An address another account uses, or the person's own, quietly does nothing, so the screen never reveals who has an account.
 */
class RequestEmailChange
{
    public function __construct(private IssueLoginLink $issueLoginLink) {}

    public function handle(User $user, string $newEmail): void
    {
        $newEmail = Str::lower($newEmail);

        // After the response is sent: the answer and how long it takes must not depend on whether the address has an account, and a mail server failure must not show up only for free addresses.
        defer(function () use ($user, $newEmail) {
            if ($newEmail === $user->email || User::query()->where('email', $newEmail)->exists()) {
                return;
            }

            $url = $this->issueLoginLink->handle($user, LoginLinkPurpose::EmailChange, $newEmail);

            // The person's address hasn't changed yet, so this goes to the new address directly.
            Notification::route('mail', $newEmail)->notify(new EmailChangeConfirmationNotification($url));
        });
    }
}
