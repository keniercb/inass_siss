<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\DTO;

use App\Modules\Security\Infrastructure\Persistence\Models\User;

/**
 * Immutable output of a successful login (RF-SEG-001): the
 * authenticated user plus the plain-text token issued for the
 * session. The Presentation layer maps it onto the response
 * envelope; it never re-derives business data.
 */
final class LoginResult
{
    public function __construct(
        public readonly User $user,
        public readonly string $token,
    ) {}
}
