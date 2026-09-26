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
}
