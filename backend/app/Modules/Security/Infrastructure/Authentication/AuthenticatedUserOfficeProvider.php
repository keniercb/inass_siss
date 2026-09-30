<?php

declare(strict_types=1);

namespace App\Modules\Security\Infrastructure\Authentication;

use App\Modules\Security\Infrastructure\Persistence\Models\User;
use App\Modules\Shared\Contracts\CurrentUserOfficeProviderInterface;
use Illuminate\Support\Facades\Auth;

/**
 * Auth-backed implementation of the registering office port (ADR-33).
 *
 * Resolves the office of the user behind the running request through
 * the default guard first (session guard, also what feature tests
 * use) and falls back to the sanctum guard (stateless Bearer-token
 * API requests) — the same resolution order as its sibling
 * AuthenticatedUserIdProvider. The raw users.office_id column
 * answers: whether that office is still ACTIVE is the caller's
 * semantic rule (the Organizations directory probe), never this
 * adapter's concern, so a deactivated office surfaces here as an id
 * and the business layer gets to answer a conversational 422.
 */
final class AuthenticatedUserOfficeProvider implements CurrentUserOfficeProviderInterface
{
    public function currentOfficeId(): ?int
    {
        $identifier = Auth::id() ?? Auth::guard('sanctum')->id();

        if (! is_numeric($identifier) || $identifier === null) {
            return null;
        }

        $officeId = User::query()
            ->whereKey((int) $identifier)
            ->value('office_id');

        return $officeId === null ? null : (int) $officeId;
    }
}
