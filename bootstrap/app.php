<?php

use App\Http\Middleware\CheckInstitutionScope;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\CheckFacultyPermission;
use App\Http\Middleware\CheckSetupComplete;
use App\Http\Middleware\CheckSubscription;
use App\Http\Middleware\SingleSessionLock;
use App\Http\Middleware\ProctorHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'institution.scope'    => CheckInstitutionScope::class,
            'role'                 => CheckRole::class,
            'faculty.permission'   => CheckFacultyPermission::class,
            'setup.complete'       => CheckSetupComplete::class,
            'subscription'         => CheckSubscription::class,
            'single.session'       => SingleSessionLock::class,
            'proctor.headers'      => ProctorHeaders::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Resource not found.'], 404);
            }
        });

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        });
    })->create();
