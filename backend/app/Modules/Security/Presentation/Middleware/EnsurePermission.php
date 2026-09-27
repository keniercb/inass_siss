<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level authorization for the SGP API (RF-SEG-002, ADR-18).
 *
 * Authorization lives in the backend, never in the client: every
 * protected route declares the required "modulo.accion" permission
 * and this guard resolves it against the spatie tables seeded from
 * the PermissionMatrix. It relies on the Gate::before hook the
 * package registers (checkPermissionTo), which answers false for
 * unknown permissions instead of failing, and on the user resolver
 * already set by the auth middleware in front of it, so it stays
 * guard-agnostic (session or bearer token alike).
 */
final class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        abort_unless($user !== null && $user->can($permission), 403, 'Forbidden.');

        return $next($request);
    }
}
