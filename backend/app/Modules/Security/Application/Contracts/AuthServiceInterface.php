<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\Contracts;

use App\Modules\Security\Application\DTO\LoginResult;
use App\Modules\Security\Application\Exceptions\PasswordExpiredException;
use App\Modules\Security\Infrastructure\Persistence\Models\User;

/**
 * Authentication use-case port (RF-SEG-001).
 *
 * Declared in the Application layer so Presentation depends on an
 * abstraction instead of a concrete service (DIP, architecture doc
 * section 7). Controllers type-hint this contract, while
 * SecurityServiceProvider binds the default implementation: unit
 * tests of the HTTP layer can substitute a stub, and decorated
 * implementations (audit logging, rate limiting) can be wired
 * without touching a single controller.
 */
interface AuthServiceInterface
{
    /**
     * Attempts to authenticate the given credentials and issue a
     * personal access token. Returns null on invalid credentials:
     * unknown email and wrong password intentionally collapse into
     * the same outcome so responses never reveal which one failed.
     * Wrong passwords also feed the brute-force lockout counter
     * (RF-SEC-001, ADR-24); locked accounts answer null as well.
     *
     * @throws PasswordExpiredException credentials valid but the
     *                                  optional expiry landed (no
     *                                  token is issued)
     */
    public function login(string $email, string $password): ?LoginResult;

    /**
     * Self-service password renewal (RF-SEC-001 "renovación",
     * ADR-24): verifies the current password, applies the password
     * policy to the new one and revokes every OTHER session token —
     * the token of the running request survives so the session stays
     * alive. Wrong current password or an unchanged new password
     * throw a 422-semantic ValidationException.
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): User;

    /**
     * Revokes the personal access token used by the current request,
     * closing the session.
     */
    public function logout(User $user): void;
}
