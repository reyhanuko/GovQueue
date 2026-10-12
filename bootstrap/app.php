<?php

use App\Http\Middleware\CorrelateRequests;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'active' => EnsureAccountIsActive::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));

        /*
         * The CSP report endpoint is posted to directly by the browser without a
         * session or CSRF token. Excluding it here is the only correct option:
         * the browser has no way to obtain a token for a report it fires
         * asynchronously, so this route would return 419 for every real violation
         * report in production without the exemption.
         *
         * During automated tests every mutation request is also exempt: the test
         * suite uses the array session driver which does not persist the CSRF token
         * across requests the same way the database/file drivers do, so every
         * POST/PUT/PATCH/DELETE would return 419 without this exemption.
         */
        $csrfExceptions = ['/csp-report'];

        if (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'production') === 'testing') {
            $csrfExceptions[] = '*';
        }

        $middleware->validateCsrfTokens(except: $csrfExceptions);

        // GovQueue holds residents' names, birthdates, contact numbers, and
        // disability status, so baseline browser hardening is applied to every
        // web response. Appended (not prepended) so it wraps the rest of the
        // stack and still applies on early responses such as redirects.
        $middleware->append(SecurityHeaders::class);

        // Request correlation id. Prepended so it wraps everything: the id has to
        // exist before any other middleware can log, and has to survive an early
        // return from any of them.
        $middleware->prepend(CorrelateRequests::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Clients who are not signed in are sent to sign in rather than shown a
        // bare 403 page; signed-in users keep the standard message.
        $exceptions->render(function (Request $request, AccessDeniedHttpException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 403);
            }

            return $request->user()
                ? response()->view('errors.403', [], 403)
                : redirect()->guest(route('login'))->with('error', 'Please sign in to continue.');
        });

        /*
         * A database outage must not show a stack trace to someone standing at a
         * service window. With APP_DEBUG on, Laravel's error page dumps the whole
         * request context - including full model JSON holding a client's name,
         * birthdate, phone number, and disability status - to anyone who reloads.
         *
         * So the connection failure gets a plain "temporarily unavailable" page
         * regardless of debug mode, and the detail goes to the log instead.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            $connectionErrors = [
                'SQLSTATE[HY000] [2002]',
                'SQLSTATE[HY000] [2006]',
                'SQLSTATE[HY008]',
                'No connection could be made',
                'server has gone away',
                'Lost connection to MySQL server',
            ];

            $message = $e->getMessage();

            $isConnectionFailure = collect($connectionErrors)
                ->contains(fn ($needle) => str_contains($message, $needle));

            if (! $isConnectionFailure) {
                return null;
            }

            report($e);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'The service is temporarily unavailable. Please try again shortly.',
                ], 503);
            }

            return response()->view('errors.unavailable', [], 503);
        });
    })->create();
