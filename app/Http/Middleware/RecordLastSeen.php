<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes when a signed-in person was last around, at most once an hour so it doesn't write on every page. It's a query-builder update on purpose: activity isn't a change to anyone's record, so it fires no model events and adds nothing to the change log. It runs after EnsureAccountIsSecure, which turns away deactivated people before this is reached.
 */
class RecordLastSeen
{
    public const EVERY_MINUTES = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ($user->last_seen_at === null || $user->last_seen_at->lt(now()->subMinutes(self::EVERY_MINUTES)))) {
            $now = now();
            User::query()->whereKey($user->id)->update(['last_seen_at' => $now]);
            $user->setAttribute('last_seen_at', $now);
            $user->syncOriginalAttribute('last_seen_at');
        }

        return $next($request);
    }
}
