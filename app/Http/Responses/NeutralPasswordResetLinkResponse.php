<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * The answer to "send me a password reset link". It is the same for every address, so it never reveals whether an account exists. Fortify's broker also answers "invalid user" or "throttled" only for some addresses, so those get this answer too.
 */
class NeutralPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public const MESSAGE = 'If that address has an account, we\'ve emailed a link to reset your password.';

    public function __construct(protected string $status) {}

    public function toResponse($request)
    {
        return $request->wantsJson()
            ? new JsonResponse(['message' => self::MESSAGE], 200)
            : back()->withInput($request->only('email'))->with('status', self::MESSAGE)->with('reset_link_sent', true);
    }
}
