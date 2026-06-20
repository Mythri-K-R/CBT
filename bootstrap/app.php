<?php

use App\Http\Middleware\CheckInstitutionScope;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\CheckFacultyPermission;
use App\Http\Middleware\CheckSetupComplete;
use App\Http\Middleware\CheckSubscription;
use App\Http\Middleware\SingleSessionLock;
use App\Http\Middleware\ProctorHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web:      __DIR__ . '/../routes/web.php',
        api:      __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health:   '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // ── Sanctum stateful requests ─────────────────────────────────────
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        // ── Global security headers on every response ─────────────────────
        $middleware->web(append: [
            \Illuminate\Http\Middleware\SetCacheHeaders::class,
        ]);

        // ── Custom middleware aliases ──────────────────────────────────────
        $middleware->alias([
            'institution.scope'  => CheckInstitutionScope::class,
            'role'               => CheckRole::class,
            'faculty.permission' => CheckFacultyPermission::class,
            'setup.complete'     => CheckSetupComplete::class,
            'subscription'       => CheckSubscription::class,
            'single.session'     => SingleSessionLock::class,
            'proctor.headers'    => ProctorHeaders::class,
        ]);

        // ── CSRF exceptions ───────────────────────────────────────────────
        $middleware->validateCsrfTokens(except: ['api/*']);

        // ── Trusted proxies (for Nginx load balancer / cloud) ─────────────
        // Set APP_TRUSTED_PROXIES=* in .env when behind a load balancer to
        // ensure X-Forwarded-For is trusted for rate limiting by real IP.
        $middleware->trustProxies(
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR |
                     \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST |
                     \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT |
                     \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {

        // ── Render ALL exceptions as JSON for api/* routes ────────────────
        // Production never exposes stack traces. Errors are logged; clients
        // receive friendly messages with correct HTTP status codes.

        // 404 — Resource not found
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Resource not found.'], 404);
            }
        });

        // 404 — Model not found (route model binding)
        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $model = class_basename($e->getModel());
                return response()->json(['message' => "{$model} not found."], 404);
            }
        });

        // 401 — Unauthenticated
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated. Please log in.'], 401);
            }
        });

        // 403 — Forbidden / unauthorised
        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'You do not have permission to perform this action.'], 403);
            }
        });

        // 422 — Validation errors
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors'  => $e->errors(),
                ], 422);
            }
        });

        // 429 — Rate limit exceeded
        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $retryAfter = $e->getHeaders()['Retry-After'] ?? 60;
                return response()->json([
                    'message'     => 'Too many requests. Please slow down.',
                    'retry_after' => (int) $retryAfter,
                ], 429)->withHeaders(['Retry-After' => $retryAfter]);
            }
        });

        // 401 — Invalid signature (email verification links, etc.)
        $exceptions->render(function (InvalidSignatureException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'This link is invalid or has expired.'], 401);
            }
        });

        // 500 — Database errors (never expose query to client)
        $exceptions->render(function (QueryException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                // Log the full SQL error for internal debugging
                \Illuminate\Support\Facades\Log::error('Database error', [
                    'sql'     => $e->getSql(),
                    'message' => $e->getMessage(),
                    'url'     => $request->fullUrl(),
                ]);

                return response()->json([
                    'message' => 'A database error occurred. Please try again or contact support.',
                ], 500);
            }
        });

        // Generic HTTP exceptions (403, 503, etc.)
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage() ?: 'An HTTP error occurred.',
                ], $e->getStatusCode());
            }
        });

        // ── Reportable: ensure stack traces are logged in production ──────
        // (Laravel logs by default, but being explicit is good practice)
        $exceptions->reportable(function (\Throwable $e) {
            // Return false to prevent duplicate logging if you use Sentry/Bugsnag.
            // return false;
        });
    })
    ->create();
