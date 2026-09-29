<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\Contracts;

use App\Modules\Security\Application\Exceptions\PersonAlreadyLinkedException;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * User account use cases (S3.5 + S3.6): the user ↔ person association
 * that grounds action traceability (RF-SEG-004) and the
 * administrative account lifecycle (RF-SEG-001 lockout/unlock,
 * RF-AUD-004 deactivate/restore, ADR-24). Business rules live here
 * (defense in depth): the HTTP validation is a convenience mirror,
 * the database constraints are the final backstop.
 */
interface UserServiceInterface
{
    /**
     * Resolves one ACTIVE account by id (deactivated accounts answer
     * null: their email/person stay reserved but they are no longer
     * part of the operating surface).
     */
    public function find(int $userId): ?User;

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

    /**
     * Registers a new account with its initial roles (S3.6,
     * RF-SEG-001). The email is reserved by active AND deactivated
     * accounts (natural-key reservation), the password must satisfy
     * the password policy and the roles must be institutional roles
     * of the PermissionMatrix. The optional territorial office
     * (ADR-29) must reference an ACTIVE office — validated on the
     * wire and probed here so the service is safe to call from any
     * entry point. Semantic failures throw 422.
     *
     * @param  list<string>  $roles
     *
     * @throws ValidationException 422 (email reserved, weak password, unknown role, unknown/deactivated office)
     */
    public function createUser(string $name, string $email, string $password, array $roles, ?int $officeId = null): User;

    /**
     * Edits an active account: display name, role assignment and
     * territorial office. The email is immutable (identity key of the
     * account). officeId follows PATCH semantics (ADR-29): the flag
     * marks whether the client sent the key at all — present with a
     * null it reassigns to "no office", absent it stays untouched.
     * Returns null when the account does not exist.
     *
     * @param  list<string>|null  $roles  null leaves the assignment untouched
     *
     * @throws ValidationException 422 (email change, unknown role, demoting the last active administrator, unknown/deactivated office)
     */
    public function updateUser(
        int $userId,
        ?string $name,
        ?array $roles,
        ?string $email = null,
        ?int $officeId = null,
        bool $officeIdPresent = false,
    ): ?User;

    /**
     * Deactivates the account (soft delete, RF-AUD-004) and revokes
     * all its tokens: a deactivated account keeps reserving its email
     * and person, but neither authenticates nor holds sessions. The
     * acting administrator cannot deactivate their own account nor
     * the last active administrator. Returns null when the account
     * does not exist.
     *
     * @throws ValidationException 422 (self-deactivation, last administrator)
     */
    public function deactivate(int $userId, int $actingUserId): ?User;

    /**
     * Reactivates a deactivated account (Administrator, RF-AUD-004,
     * audited). Idempotent for an already-active account. Returns
     * null when no account (active or deactivated) exists.
     */
    public function restore(int $userId): ?User;

    /**
     * Unlocks an account locked by failed attempts (RF-SEC-001:
     * "desbloqueo por el Administrador"). Idempotent for a clean
     * account (no write). Returns null when the account does not
     * exist.
     */
    public function unlock(int $userId): ?User;

    /**
     * Administrator password reset: applies the password policy,
     * stamps a fresh password_changed_at baseline, clears any
     * lockout and revokes ALL sessions (the reset forces a new
     * login). Returns null when the account does not exist.
     *
     * @throws ValidationException 422 (weak password)
     */
    public function resetPassword(int $userId, string $password): ?User;

    /**
     * Paginated account directory for the administrative surface:
     * free-text over name/email, exact role and status (active,
     * inactive, all — default active).
     *
     * @param  array{q?: string, role?: string, status?: string}  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator;
}
