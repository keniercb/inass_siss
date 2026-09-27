<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\Services;

use App\Modules\Security\Application\Contracts\UserRepositoryInterface;
use App\Modules\Security\Application\Contracts\UserServiceInterface;
use App\Modules\Security\Application\Exceptions\PersonAlreadyLinkedException;
use App\Modules\Security\Infrastructure\Persistence\Models\User;

/**
 * User ↔ person association use cases (S3.5, RF-SEG-004).
 *
 * Owns the uniqueness rule: one person backs at most one account.
 * The check runs here (defense in depth: the policy lives in the
 * application layer, not only in the HTTP validation) and the
 * users.person_id UNIQUE constraint enforces it as the final
 * backstop. Persistence is delegated to the repository port, so the
 * service stays unit-testable without a database (ADR-11).
 */
final class UserService implements UserServiceInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
    ) {}

    public function linkPerson(int $userId, int $personId): ?User
    {
        $user = $this->users->findById($userId);

        if ($user === null) {
            return null;
        }

        $owner = $this->users->findOwnerOfPerson($personId);

        if ($owner !== null) {
            if ($owner->id !== $user->id) {
                throw new PersonAlreadyLinkedException($personId, $owner->id);
            }

            // Idempotent: the account already holds exactly this
            // person, so there is nothing to write.
            return $user;
        }

        return $this->users->linkPerson($user, $personId);
    }

    public function unlinkPerson(int $userId): ?User
    {
        $user = $this->users->findById($userId);

        if ($user === null) {
            return null;
        }

        if ($user->person_id === null) {
            // Idempotent: nothing to remove.
            return $user;
        }

        return $this->users->unlinkPerson($user);
    }
}
