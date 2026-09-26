<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\Services;

use App\Modules\Security\Application\Contracts\UserRepositoryInterface;
use App\Modules\Security\Application\DTO\LoginResult;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * Authentication use cases (RF-SEG-001).
 *
 * Owns the business rules of the module: credential verification,
 * token issuance and token revocation. Data access is delegated to
 * the user repository port and hashing goes through the Hasher
 * contract, so the service is unit-testable without the container.
 *
 * The hardening pass (brute-force lockout policies, password
 * lifecycle, token scopes) belongs to phase 6 and is tracked there;
 * only basic rate limiting is active now.
 */
final class AuthService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly Hasher $hasher,
    ) {}

    /**
     * Attempts to authenticate the given credentials and issue a
     * personal access token. Returns null on invalid credentials:
     * unknown email and wrong password intentionally collapse into
     * the same outcome so responses never reveal which one failed.
     */
    public function login(string $email, string $password): ?LoginResult
    {
        $user = $this->users->findByEmail($email);

        if ($user === null || ! $this->hasher->check($password, (string) $user->password)) {
            return null;
        }

        return new LoginResult(
            user: $user,
            token: $this->users->issueAccessToken($user),
        );
    }

    /**
     * Revokes the token used by the current request, closing the
     * session.
     */
    public function logout(User $user): void
    {
        $this->users->revokeCurrentAccessToken($user);
    }
}
