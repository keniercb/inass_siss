<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\Contracts;

use App\Modules\Security\Infrastructure\Persistence\Models\User;

/**
 * Persistence port for the Security user aggregate (RF-SEG-001).
 *
 * Declared in the Application layer so use cases depend on the
 * abstraction (DIP, architecture doc section 7) while the Eloquent
 * implementation stays confined to Infrastructure. Swapping the data
 * store or injecting an in-memory fake in tests never touches the
 * business logic.
 */
interface UserRepositoryInterface
{
    /**
     * Returns the user owning the given email address, or null when
     * the address is unknown to the system.
     */
    public function findByEmail(string $email): ?User;

    /**
     * Issues a new personal access token for the given user and
     * returns its plain-text value (shown once, at issuance only).
     */
    public function issueAccessToken(User $user): string;

    /**
     * Revokes the personal access token used by the current request.
     */
    public function revokeCurrentAccessToken(User $user): void;
}
