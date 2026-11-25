<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Takes the rate limit headers off the answers to the sign-in requests (password login, login link and reset link requests). A request stopped by a daily limit for an address answers exactly like any other, and without this the normal answers would carry a count of tries left that the stopped ones don't. It runs outside the throttle middleware (bootstrap/app.php puts it just before ThrottleRequests in the priority list), so it sees the final answer. The "Too many tries" page still says how long to wait, because it reads that from the limit itself.
 */
class HideRateLimitHeaders
{
    public const HEADERS = ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset', 'Retry-After'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $header) {
            $response->headers->remove($header);
        }

        return $response;
    }
}
