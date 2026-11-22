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
    protected $fillable = ['user_id', 'purpose', 'email', 'destination', 'token_hash', 'expires_at', 'used_at'];

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

    /** The path and query of a URL on this app's own host, or null for anything else (another host, a protocol-relative `//` address, a path that doesn't start with a single `/`, or more than 500 characters). Used so a login link can only ever lead somewhere on this site. */
    public static function safeDestination(?string $url): ?string
    {
        if ($url === null || $url === '' || strlen($url) > 500) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        if (isset($parts['scheme']) || isset($parts['host'])) {
            if (! isset($parts['host']) || strcasecmp($parts['host'], (string) parse_url(config('app.url'), PHP_URL_HOST)) !== 0) {
                return null;
            }
        }

        $path = $parts['path'] ?? '';

        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        $destination = $path.(isset($parts['query']) ? '?'.$parts['query'] : '');

        return strlen($destination) > 500 ? null : $destination;
    }

    /** The link for this token if it can still be used: it exists, is one of the given kinds, is unused and unexpired, and its person can sign in. */
    public static function findUsable(string $token, LoginLinkPurpose ...$purposes): ?self
    {
        $link = static::query()->with('user')->where('token_hash', hash('sha256', $token))->first();

        if ($link === null || ! in_array($link->purpose, $purposes, true) || $link->used_at !== null || $link->expires_at->isPast() || ! $link->user->canSignIn()) {
            return null;
        }

        return $link;
    }

    /** Uses up the link for this token if it can still be used. Only one of two simultaneous uses can win; the loser gets null. */
    public static function claim(string $token, LoginLinkPurpose ...$purposes): ?self
    {
        $link = static::findUsable($token, ...$purposes);

        if ($link === null) {
            return null;
        }

        $claimed = static::query()->whereKey($link->id)->whereNull('used_at')->update(['used_at' => now()]);

        return $claimed === 1 ? $link->fresh('user') : null;
    }
}
