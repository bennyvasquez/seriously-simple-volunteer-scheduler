<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as BaseValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A form posted after its page was open longer than the session (#80) goes back to that page, as a GET, to try again. A post whose address has no page (log out) keeps Laravel's 419 page.
 *
 * This is a middleware, not an exception render callback, so the redirect is a normal response: the session middleware then saves the flashed message and sets the session cookie. An exception handled outside the middleware stack loses both.
 */
class ValidateCsrfToken extends BaseValidateCsrfToken
{
    public const EXPIRED_MESSAGE = 'This page was open too long, so nothing was saved. Please try again.';

    public function handle($request, Closure $next): Response
    {
        try {
            return parent::handle($request, $next);
        } catch (TokenMismatchException $exception) {
            return $this->backToThePage($request) ?? throw $exception;
        }
    }

    private function backToThePage(Request $request): ?Response
    {
        if (! $request->isMethod('POST')) {
            return null;
        }

        $path = $request->getPathInfo();

        try {
            app('router')->getRoutes()->match(Request::create($path, 'GET'));
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return null;
        }

        // The path only, never a URL from the Referer or the input, and no old input, so nothing typed on the login form (an email address) comes back.
        return redirect($path)->with('status', self::EXPIRED_MESSAGE);
    }
}
