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
}
