<?php

namespace App\Actions\Accounts;

use App\Enums\LoginLinkPurpose;
use App\Models\LoginLink;

/**
 * Uses up a login link. Returns null for an unknown, used or expired link, one whose person was deactivated, or a link for something other than logging in (confirming it's you, or a new email address).
 */
class ConsumeLoginLink
{
    public function handle(string $token): ?LoginLink
    {
        return LoginLink::claim($token, ...LoginLinkPurpose::forLoggingIn());
    }
}
