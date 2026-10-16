<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admins, coordinators and area leads can see volunteers' contact details, so until they have a password and confirmed two-factor they can only reach the screens that set those up (spec section 13). It runs on every web route, so routes added later are covered without anyone remembering to add it.
 */
class EnsureAccountIsSecure
{
    /** Routes someone with a role needs while finishing setup. */
    public const ALLOWED_DURING_SETUP = [
        'account.security',
        'account.password.set',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->requiresTwoFactor()) {
            return $next($request);
        }

        if ($user->password !== null && $user->hasEnabledTwoFactorAuthentication()) {
            return $next($request);
        }

        if ($request->routeIs(...self::ALLOWED_DURING_SETUP)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Set up a password and two-factor authentication first.'], 403);
        }

        return redirect()->route('account.security');
    }
}
