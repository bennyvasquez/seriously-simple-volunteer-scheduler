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
        $link = LoginLink::query()->with('user')->where('token_hash', hash('sha256', $token))->first();

        if ($link === null || $link->used_at !== null || $link->expires_at->isPast() || ! $link->user->isActive()) {
            return null;
        }

        // Only one of two simultaneous uses can win.
        $claimed = LoginLink::query()->whereKey($link->id)->whereNull('used_at')->update(['used_at' => now()]);

        return $claimed === 1 ? $link->fresh('user') : null;
    }
}
