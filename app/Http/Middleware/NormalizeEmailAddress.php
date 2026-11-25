<?php

namespace App\Http\Middleware;

use App\Support\EmailAddress;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where a person types an email address (signing in, asking for a login link or a password reset, changing their address, inviting someone), the address is trimmed and lowercased, and refused with the same message for every address if it has anything but plain letters, numbers and symbols. The sign-in limits build their keys from `EmailAddress::normalize()` themselves, because Laravel runs the throttle middleware before this one, so they count each spelling of an address once either way. This middleware acts only on the routes named here.
 */
class NormalizeEmailAddress
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('login.store', 'login-link.request', 'password.email', 'password.update', 'account.email.update', 'people.invite.store') && is_string($request->input('email'))) {
            $address = EmailAddress::normalize($request->input('email'));

            $request->merge(['email' => $address]);

            if (! EmailAddress::isPlain($address)) {
                throw ValidationException::withMessages(['email' => EmailAddress::REFUSAL]);
            }
        }

        return $next($request);
    }
}
