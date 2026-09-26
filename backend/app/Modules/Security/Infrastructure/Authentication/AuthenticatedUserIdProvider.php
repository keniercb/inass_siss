<?php

declare(strict_types=1);

namespace App\Modules\Security\Infrastructure\Authentication;

use App\Modules\Shared\Contracts\CurrentUserProviderInterface;
use Illuminate\Support\Facades\Auth;

/**
 * Auth-backed implementation of the audit actor port (ADR-14).
 *
 * Resolves the user behind the running request through the default
 * guard first (session guard, also what feature tests use) and falls
 * back to the sanctum guard (stateless Bearer-token API requests).
 * CLI and anonymous contexts resolve to null, leaving audit columns
 * untouched.
 */
final class AuthenticatedUserIdProvider implements CurrentUserProviderInterface
{
    public function currentUserId(): ?int
    {
        $identifier = Auth::id() ?? Auth::guard('sanctum')->id();

        if (is_int($identifier)) {
            return $identifier;
        }

        return is_numeric($identifier) ? (int) $identifier : null;
    }
}
