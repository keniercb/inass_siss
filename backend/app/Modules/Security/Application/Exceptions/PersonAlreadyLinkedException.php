<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\Exceptions;

use RuntimeException;

/**
 * Raised when a link attempt targets a person that already belongs
 * to another account (S3.5, RF-SEG-004: one person backs at most one
 * user).
 *
 * The controller answers HTTP 409 carrying the owning account, so
 * the administrator resolves the conflict conversationally instead
 * of guessing. Soft-deleted owners are reported too: the database
 * constraint reserves the person even for deactivated accounts.
 */
final class PersonAlreadyLinkedException extends RuntimeException
{
    public function __construct(
        public readonly int $personId,
        public readonly int $currentUserId,
    ) {
        parent::__construct('The person is already linked to another user.');
    }
}
