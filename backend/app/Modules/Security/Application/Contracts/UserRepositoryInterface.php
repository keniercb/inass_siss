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

    /**
     * Returns the account with the given id, or null when it does
     * not exist.
     */
    public function findById(int $id): ?User;

    /**
     * Returns the account that currently owns the given person,
     * including soft-deleted accounts: the users.person_id UNIQUE
     * constraint reserves the person for any account, active or
     * deactivated, so the ownership answer must mirror the database.
     */
    public function findOwnerOfPerson(int $personId): ?User;

    /**
     * Persists the association (the audited write lands in the
     * bitácora through the observers already observing User) and
     * returns the refreshed account.
     */
    public function linkPerson(User $user, int $personId): User;

    /**
     * Clears the association (audited as well) and returns the
     * refreshed account.
     */
    public function unlinkPerson(User $user): User;
}
