<?php

namespace App\Models;

use App\Enums\LoginLinkPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use login link sent by email. Only a hash of the token is stored, so a database leak doesn't leak working links.
 */
class LoginLink extends Model
{
    protected $fillable = ['user_id', 'purpose', 'token_hash', 'expires_at', 'used_at'];

    protected function casts(): array
    {
        return [
            'purpose' => LoginLinkPurpose::class,
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The link for this token if it can still be used: it exists, is unused and unexpired, and its person is active. */
    public static function findUsable(string $token): ?self
    {
        $link = static::query()->with('user')->where('token_hash', hash('sha256', $token))->first();

        if ($link === null || $link->used_at !== null || $link->expires_at->isPast() || ! $link->user->isActive()) {
            return null;
        }

        return $link;
    }
}
