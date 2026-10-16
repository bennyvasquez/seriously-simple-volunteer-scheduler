<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admins, coordinators and area leads can see volunteers' contact details, so until they have a password and confirmed two-factor they can only reach the screens that set those up (spec section 13). It also ends the session of anyone whose account has been turned off, which covers sessions that deleting session rows can't reach. It runs on every web route, so routes added later are covered without anyone remembering to add it.
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
        'account.sign-out-everywhere',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if (! $user->isActive()) {
            return $this->endSessionOfDeactivatedUser($request);
        }

        if (! $user->requiresTwoFactor()) {
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

    private function endSessionOfDeactivatedUser(Request $request): Response
    {
        $message = 'This account has been turned off. If you think that\'s a mistake, ask a coordinator.';

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
