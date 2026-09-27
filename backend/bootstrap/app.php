<?php

use App\Modules\Security\Presentation\Middleware\EnsurePermission;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API-only backend: unauthenticated requests must not attempt the
        // framework's default guest redirect to a "login" route that does
        // not exist here; the exception is rendered as JSON 401 below.
        $middleware->redirectGuestsTo(fn () => null);

        // Route-level RBAC (RF-SEG-002, ADR-18): protected routes declare
        // `permission:modulo.accion` and the Security-owned guard resolves
        // it against the spatie tables seeded from the PermissionMatrix.
        $middleware->alias([
            'permission' => EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API-only backend (RF-API-002): unauthenticated requests under
        // /api/* answer JSON 401 instead of the default redirect to a
        // (non-existent) login route.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return null;
        });
    })->create();
