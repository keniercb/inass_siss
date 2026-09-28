<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\Services;

use App\Modules\Security\Application\Contracts\AuthServiceInterface;
use App\Modules\Security\Application\Contracts\UserRepositoryInterface;
use App\Modules\Security\Application\DTO\LoginResult;
use App\Modules\Security\Application\Exceptions\PasswordExpiredException;
use App\Modules\Security\Domain\Authentication\LockoutPolicy;
use App\Modules\Security\Domain\Authentication\PasswordPolicy;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use App\Modules\Shared\Contracts\ClockInterface;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Validation\ValidationException;

/**
 * Authentication use cases (RF-SEC-001, ADR-24).
 *
 * Owns the module's business rules: credential verification, the
 * brute-force lockout (failed attempts feed the counter, the Nth
 * failure stamps the lock, locked accounts are rejected without
 * further counting and the TTL auto-expires by the calendar), the
 * optional password expiry and the self-service renewal. Data access
 * is delegated to the user repository port, hashing goes through the
 * Hasher contract and every date-sensitive decision resolves through
 * the Shared clock port, so the service is unit-testable without the
 * container.
 */
final class AuthService implements AuthServiceInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly Hasher $hasher,
        private readonly ClockInterface $clock,
        private readonly LockoutPolicy $lockout,
        private readonly PasswordPolicy $password,
    ) {}

    /**
     * Attempts to authenticate the given credentials and issue a
     * personal access token. Returns null on invalid credentials:
     * unknown email and wrong password intentionally collapse into
     * the same outcome so responses never reveal which one failed.
     * Locked accounts answer null too (same generic 401: the lock
     * state itself is not disclosed to unauthenticated callers).
     */
    public function login(string $email, string $password): ?LoginResult
    {
        $user = $this->users->findByEmail($email);

        if ($user === null) {
            return null;
        }

        if ($this->lockout->isLocked($user->locked_at, $this->clock)) {
            return null;
        }

        if (! $this->hasher->check($password, (string) $user->password)) {
            $state = $this->lockout->registerFailure(
                (int) $user->failed_login_attempts,
                $user->locked_at,
                $this->clock,
            );

            $this->users->recordFailedAttempt($user, $state);

            return null;
        }

        $this->users->clearLoginFailures($user);

        if ($this->password->requiresRenewal($user->password_changed_at, $this->clock)) {
            throw new PasswordExpiredException;
        }

        return new LoginResult(
            user: $user,
            token: $this->users->issueAccessToken($user),
        );
    }

    /**
     * Self-service password renewal: the current secret is verified
     * (a wrong one answers 422 on the field), the new one must
     * satisfy the password policy and differ from the current, and
     * every OTHER session token is revoked — the token of the
     * running request survives, so the caller keeps working.
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): User
    {
        if (! $this->hasher->check($currentPassword, (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        if ($this->hasher->check($newPassword, (string) $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'The new password must be different from the current password.',
            ]);
        }

        $this->guardPasswordPolicy($newPassword);

        $user = $this->users->resetPassword($user, $newPassword, $this->clock->now());
        $this->users->revokeOtherTokens($user);

        return $user;
    }

    /**
     * Revokes the token used by the current request, closing the
     * session.
     */
    public function logout(User $user): void
    {
        $this->users->revokeCurrentAccessToken($user);
    }

    /**
     * Defense in depth: the FormRequest rule mirrors the policy for
     * the 422 field error, but the service re-checks the domain
     * value so any caller (CLI, integrator) gets the same guarantee.
     */
    private function guardPasswordPolicy(string $password): void
    {
        if ($this->password->violations($password) !== []) {
            throw ValidationException::withMessages([
                'password' => 'The password does not satisfy the password policy (length/complexity).',
            ]);
        }
    }
}
