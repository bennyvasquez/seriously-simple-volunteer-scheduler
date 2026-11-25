<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the security headers on every answer, error pages included (bootstrap/app.php adds it to the whole stack, so a 404 for an address with no page gets them too). Scripts and stylesheets load only from this site's own files, so the policy has no 'unsafe-inline' for them. The one exception is style attributes (style-src-attr): the calendar grid places each shift block with a computed top and height, which a stylesheet can't hold. Style attributes can't run script.
 */
class SecurityHeaders
{
    public const POLICY = "default-src 'self'; script-src 'self'; style-src 'self'; style-src-attr 'unsafe-inline'; img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', self::POLICY);
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');

        // Only where the site is really served over HTTPS, so a local install over plain HTTP isn't told to insist on it.
        if (app()->isProduction() && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
