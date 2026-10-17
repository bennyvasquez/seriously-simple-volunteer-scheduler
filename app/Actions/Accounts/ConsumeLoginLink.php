<?php

namespace App\Actions\Accounts;

use App\Models\LoginLink;

/**
 * Uses up a login link. Returns null for an unknown, used or expired link, or one whose person was deactivated.
 */
class ConsumeLoginLink
{
    public function handle(string $token): ?LoginLink
    {
        $link = LoginLink::findUsable($token);

        if ($link === null) {
            return null;
        }

        // Only one of two simultaneous uses can win.
        $claimed = LoginLink::query()->whereKey($link->id)->whereNull('used_at')->update(['used_at' => now()]);

        return $claimed === 1 ? $link->fresh('user') : null;
    }
}
