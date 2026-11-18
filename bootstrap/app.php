<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Honour the client's Accept-Language on every API call, so mail and
        // any future translated response come back in the user's language.
        $middleware->api(prepend: [
            \App\Http\Middleware\SetLocale::class,
        ]);

        // The API has no login page. Laravel's default guest redirect is
        // route('login'), which the auth middleware resolves for any request
        // that does not ask for JSON (a browser tab, a scanner) — before the
        // 401 renderer below runs — and turns it into a 500 (BR-MTUTEX).
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'auth.optional' => \App\Http\Middleware\OptionalAuth::class,
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'teacher.course' => \App\Http\Middleware\TeacherCourseAccess::class,
            'teacher.student' => \App\Http\Middleware\TeacherStudentAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Report to Sentry. A reportable() hook, so exceptions Laravel does not
        // report (ShouldntReport, HTTP 4xx) never reach it either.
        Integration::handles($exceptions);

        // Return JSON for API authentication failures instead of redirecting.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });

        // Scanners probe with query strings past PHP's max_input_vars or
        // max_input_nesting_level. Symfony re-parses the raw query with
        // parse_str() whenever the full URL is built (HandleCors does on every
        // request), and its warning arrives here as an ErrorException. That is
        // a malformed request: answer 400, not a 500 and a Sentry issue.
        $exceptions->map(function (ErrorException $e) {
            return str_starts_with($e->getMessage(), 'parse_str(): Input variable')
                ? new BadRequestHttpException('Malformed query string.', $e)
                : $e;
        });
    })->create();
