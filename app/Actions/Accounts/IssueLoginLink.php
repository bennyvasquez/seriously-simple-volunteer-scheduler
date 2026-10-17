<?php

namespace App\Actions\Accounts;

use App\Enums\LoginLinkPurpose;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates a single-use link and returns its URL. Asking again cancels the previous unused link of the same kind. An email change link carries the new address in `$email`; the other kinds don't take one.
 */
class IssueLoginLink
{
    public function handle(User $user, LoginLinkPurpose $purpose, ?string $email = null): string
    {
        if (($purpose === LoginLinkPurpose::EmailChange) !== ($email !== null)) {
            throw new InvalidArgumentException('Only an email change link carries an address, and it always does.');
        }

        $token = Str::random(48);

        $user->loginLinks()->where('purpose', $purpose->value)->whereNull('used_at')->delete();

        $user->loginLinks()->create([
            'purpose' => $purpose,
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes($purpose->lifetimeInMinutes()),
        ]);

        return route(match ($purpose) {
            LoginLinkPurpose::EmailChange => 'account.email.confirm',
            LoginLinkPurpose::ConfirmIdentity => 'account.email.identity',
            default => 'login-link.show',
        }, ['token' => $token]);
    }
}
