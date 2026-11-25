<?php

use App\Http\Middleware\EnsureAccountIsSecure;
use App\Http\Middleware\HideRateLimitHeaders;
use App\Http\Middleware\NormalizeEmailAddress;
use App\Http\Middleware\RecordLastSeen;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustConfiguredProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$expiredFormMessage = 'This page was open too long, so nothing was saved. Please try again.';

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            // Our own plain health check, outside the web group so it sets no session cookie. It answers during maintenance mode too.
            Route::middleware([])->group(__DIR__.'/../routes/health.php');
            PreventRequestsDuringMaintenance::except('up');
        },
    )
    ->withMiddleware(function (Middleware $middleware) use ($expiredFormMessage): void {
        // A second layer behind the site address Laravel uses for links (AppServiceProvider::useSiteAddress()): a request sent to any other host name is refused. Laravel skips this check in tests and in the local environment. Read from config(), not env(), so it works once the configuration is cached.
        $middleware->trustHosts(at: fn () => ['^'.preg_quote((string) parse_url((string) config('app.url'), PHP_URL_HOST)).'$'], subdomains: false);

        // Which proxies are trusted to report the visitor's address, scheme and host comes from the TRUSTED_PROXIES setting, none by default (see TrustConfiguredProxies). Laravel's own middleware is swapped for it because the configuration isn't loaded yet when this callback runs.
        $middleware->replace(TrustProxies::class, TrustConfiguredProxies::class);

        // On every answer, not only the web group's, so the error page for an address with no page gets the security headers too.
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            EnsureAccountIsSecure::class,
            NormalizeEmailAddress::class,
            RecordLastSeen::class,
        ]);

        // Listed before the throttle so it wraps it and sees the final answer, rate limit headers included (HideRateLimitHeaders).
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: HideRateLimitHeaders::class);

        // A form posted after the session ended reaches a guest in one of two ways (#80). Browsers that send Sec-Fetch-Site pass the token check, so the post arrives as a guest and the sign-in check redirects here: a post with no session, so say so (but not for log out, which is meant to end the session, or for the "Forgot your password?" button, which logs out too). Without that header the token check fails and the render callback below redirects to the page as a GET, which sends the guest here a second time: keep the message alive through that redirect.
        $middleware->redirectGuestsTo(function (Request $request) use ($expiredFormMessage) {
            if ($request->isMethod('POST') && ! $request->is('logout', 'account/forgot-password')) {
                $request->session()->flash('status', $expiredFormMessage);
            } elseif ($request->session()->get('status') === $expiredFormMessage) {
                $request->session()->reflash();
            }

            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions) use ($expiredFormMessage): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A form posted after its page was open longer than the session (#80) goes back to that page, as a GET, to try again. Laravel turns the token mismatch into a 419 HttpException before render callbacks run. A post whose address has no page (log out) returns null, so the 419 page shows.
        $exceptions->render(function (HttpException $e, Request $request) use ($expiredFormMessage) {
            if ($e->getStatusCode() !== 419 || ! $e->getPrevious() instanceof TokenMismatchException || ! $request->isMethod('POST')) {
                return null;
            }

            $path = '/'.ltrim($request->path(), '/');

            // The path is built with exactly one leading slash, so this can't trigger today. It's a guard in case the path building changes, since a path starting with two slashes would redirect off the site.
            if (str_starts_with($path, '//')) {
                return null;
            }

            try {
                app('router')->getRoutes()->match(Request::create($path, 'GET'));
            } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
                return null;
            }

            // The path only, never a URL from the Referer or the input, and no old input, so nothing typed on the login form (an email address) comes back.
            return redirect($path)->with('status', $expiredFormMessage);
        });
    })->create();
