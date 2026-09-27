<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\Contracts;

use App\Modules\Security\Application\Exceptions\PersonAlreadyLinkedException;
use App\Modules\Security\Infrastructure\Persistence\Models\User;

/**
 * User account use cases beyond the session (S3.5): the
 * user ↔ person association that grounds action traceability
 * (RF-SEG-004). Uniqueness of the association is the business rule
 * of this port and of the users.person_id constraint (backstop).
 */
interface UserServiceInterface
{
    /**
     * Links the account to a registered person (RF-SEG-004).
     * Idempotent for the same pair: the association is already
     * exactly what was asked. Returns null when the account does
     * not exist.
     *
     * @throws PersonAlreadyLinkedException 409 with the owning account
     */
    public function linkPerson(int $userId, int $personId): ?User;

    /**
     * Removes the association. Idempotent: unlinking an account
     * without a person returns it untouched. Returns null when the
     * account does not exist.
     */
    public function unlinkPerson(int $userId): ?User;
}
