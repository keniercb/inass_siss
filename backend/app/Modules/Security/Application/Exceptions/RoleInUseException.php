<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\Exceptions;

use RuntimeException;

/**
 * Raised when a deletion targets a role that accounts still hold
 * (RF-SEG-002, ADR-26): dropping the role would silently strip its
 * permissions from real accounts.
 *
 * The controller answers HTTP 409 carrying the account count, so the
 * administrator resolves the conflict conversationally — unassign
 * the role first — instead of guessing. The count spans deactivated
 * accounts too: their pivots reserve the role exactly like the
 * users.email UNIQUE reserves an address.
 */
final class RoleInUseException extends RuntimeException
{
    public function __construct(
        public readonly int $roleId,
        public readonly int $usersCount,
    ) {
        parent::__construct('The role is still assigned to accounts.');
    }
}
