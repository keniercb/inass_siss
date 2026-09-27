<?php

declare(strict_types=1);

namespace App\Modules\Security\Tests\Fakes;

use App\Modules\Security\Application\Contracts\UserRepositoryInterface;
use App\Modules\Security\Infrastructure\Persistence\Models\User;

/**
 * In-memory stand-in for the user repository port (ADR-11): lets the
 * AuthService unit tests run without a database or the container and
 * records every call so the tests can assert the interactions.
 */
final class InMemoryUserRepository implements UserRepositoryInterface
{
    public ?User $stored = null;

    public int $issuedTokens = 0;

    public bool $revokedCurrentToken = false;

    public function findByEmail(string $email): ?User
    {
        if ($this->stored === null || $this->stored->email !== $email) {
            return null;
        }

        return $this->stored;
    }

    public function issueAccessToken(User $user): string
    {
        $this->issuedTokens++;

        return 'plain-text-token';
    }

    public function revokeCurrentAccessToken(User $user): void
    {
        $this->revokedCurrentToken = true;
    }

    public function findById(int $id): ?User
    {
        return $this->stored !== null && $this->stored->id === $id
            ? $this->stored
            : null;
    }

    public function findOwnerOfPerson(int $personId): ?User
    {
        return $this->stored !== null && $this->stored->person_id === $personId
            ? $this->stored
            : null;
    }

    public function linkPerson(User $user, int $personId): User
    {
        $user->person_id = $personId;

        return $user;
    }

    public function unlinkPerson(User $user): User
    {
        $user->person_id = null;

        return $user;
    }
}
