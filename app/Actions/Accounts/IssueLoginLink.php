<?php

namespace App\Actions\Accounts;

use App\Enums\LoginLinkPurpose;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Creates a single-use login link and returns its URL. Asking again cancels the previous unused link of the same kind.
 */
class IssueLoginLink
{
    public function handle(User $user, LoginLinkPurpose $purpose): string
    {
        $token = Str::random(48);

        $user->loginLinks()->where('purpose', $purpose->value)->whereNull('used_at')->delete();

        $user->loginLinks()->create([
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes($purpose->lifetimeInMinutes()),
        ]);

        return route('login-link.show', ['token' => $token]);
    }
}
