<?php

use App\Http\Middleware\EnsureAccountIsSecure;
use App\Http\Middleware\RecordLastSeen;
use App\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as BaseValidateCsrfToken;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The expired-form redirect (#80) lives in our own CSRF middleware, which replaces Laravel's.
        $middleware->web(append: [
            EnsureAccountIsSecure::class,
            RecordLastSeen::class,
        ], replace: [
            BaseValidateCsrfToken::class => ValidateCsrfToken::class,
        ]);

        // A form posted after the session ended reaches a guest in one of two ways. Browsers that send Sec-Fetch-Site pass the token check, so the post arrives as a guest and the sign-in check redirects here: that's a post with no session, so say so (#80). Without that header the token check fails and our CSRF middleware redirects to the page as a GET, which sends the guest here a second time: keep the message alive through that redirect.
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->isMethod('POST')) {
                $request->session()->flash('status', ValidateCsrfToken::EXPIRED_MESSAGE);
            } elseif ($request->session()->get('status') === ValidateCsrfToken::EXPIRED_MESSAGE) {
                $request->session()->reflash();
            }

            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
