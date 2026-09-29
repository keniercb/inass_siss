<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\Contracts;

use App\Modules\Security\Domain\Authentication\LockoutState;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use DateTimeImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Persistence port for the Security user aggregate (RF-SEC-001).
 *
 * Declared in the Application layer so use cases depend on the
 * abstraction (DIP, architecture doc section 7) while the Eloquent
 * implementation stays confined to Infrastructure. Swapping the data
 * store or injecting an in-memory fake in tests never touches the
 * business logic.
 *
 * Audit semantics (ADR-19/ADR-24): business writes (create, profile
 * edition, unlock, password reset) persist through observers and land
 * in the append-only bitácora with sensitive attributes redacted;
 * login bookkeeping (failure counting) is deliberately silent to keep
 * the trail about business changes, not about every typo.
 */
interface UserRepositoryInterface
{
    /**
     * Returns the user owning the given email address, or null when
     * the address is unknown to the system. Deactivated accounts are
     * excluded (they cannot authenticate).
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
     * not exist (active accounts only).
     */
    public function findById(int $id): ?User;

    /**
     * Returns the account with the given id including deactivated
     * ones (soft-deleted rows), or null when nothing matches.
     */
    public function findByIdIncludingDeactivated(int $id): ?User;

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

    /**
     * Paginated account query: free text over name/email, exact
     * role membership and status (active = default scope, inactive
     * = only deactivated, all = both).
     *
     * @param  array{q?: string, role?: string, status?: string}  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator;

    /**
     * Whether the email is already taken by ANY account, active or
     * deactivated: natural-key reservation for the users.email
     * UNIQUE constraint (which spans soft-deleted rows).
     */
    public function emailTaken(string $email): bool;

    /**
     * Creates the account with its initial roles and the password
     * baseline. Audited (created event, password redacted).
     *
     * @param  list<string>  $roles
     */
    public function createUser(string $name, string $email, string $password, array $roles, DateTimeImmutable $passwordChangedAt): User;

    /**
     * Edits the display name and/or the role assignment. Audited
     * (profile update + explicit roles entry with previous values).
     *
     * @param  list<string>|null  $roles
     */
    public function updateUser(User $user, ?string $name, ?array $roles): User;

    /**
     * Soft-deletes the account and revokes every token (the
     * deactivated account reserves its email and person but loses
     * all sessions). Audited (deleted event).
     */
    public function deactivate(User $user): void;

    /**
     * Restores a deactivated account. Audited (restored event).
     */
    public function restore(User $user): User;

    /**
     * Clears the lockout bookkeeping (attempts + lock instant).
     * Audited as a normal update so the Administrator unlock stays
     * in the trail with the previous lock state.
     */
    public function unlock(User $user): User;

    /**
     * Persists a failed-login transition (counter and possible new
     * lock instant). Silent by design: security bookkeeping, not a
     * business change (ADR-24).
     */
    public function recordFailedAttempt(User $user, LockoutState $state): void;

    /**
     * Resets the failure counter and clears the lock after a
     * successful login. Silent for the same reason, and a no-op for
     * an already-clean account.
     */
    public function clearLoginFailures(User $user): void;

    /**
     * Sets the password with a fresh baseline and clears any lockout
     * in one audited write (password redacted in the trail).
     */
    public function resetPassword(User $user, string $password, DateTimeImmutable $changedAt): User;

    /**
     * Revokes every personal access token of the account.
     */
    public function revokeAllTokens(User $user): void;

    /**
     * Revokes every personal access token EXCEPT the one used by the
     * running request (self-service renewal keeps its session).
     */
    public function revokeOtherTokens(User $user): void;

    /**
     * Current role names of the account (spatie pivot read) in their
     * stored order. Guards such as the last-administrator rule read
     * the assignment through this port instead of the model relation
     * so use cases stay unit-testable without a database.
     *
     * @return list<string>
     */
    public function roleNamesOf(User $user): array;

    /**
     * Which of the candidate role names do not exist in the role
     * directory (guard web). Role assignment accepts institutional
     * AND custom roles (RF-SEG-002, ADR-26), so the catalog is the
     * database itself; unknown names answer 422 with the offending
     * entries instead of silently granting nothing.
     *
     * @param  list<string>  $roles
     * @return list<string> the unknown names, in arrival order
     */
    public function unknownRoles(array $roles): array;

    /**
     * Number of ACTIVE accounts holding the admin role, excluding
     * the given one (last-administrator guard).
     */
    public function countActiveAdministrators(User $except): int;
}
