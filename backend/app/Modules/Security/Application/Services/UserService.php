<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\Services;

use App\Modules\Security\Application\Contracts\UserRepositoryInterface;
use App\Modules\Security\Application\Contracts\UserServiceInterface;
use App\Modules\Security\Application\Exceptions\PersonAlreadyLinkedException;
use App\Modules\Security\Domain\Authentication\PasswordPolicy;
use App\Modules\Security\Domain\Authorization\PermissionMatrix;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use App\Modules\Shared\Contracts\ClockInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * User account use cases (S3.5 + S3.6, RF-SEG-001/004, RF-AUD-004,
 * ADR-24): the user ↔ person association and the administrative
 * account lifecycle.
 *
 * Every rule is decided here (defense in depth): reserved natural
 * key (email, spanning deactivated accounts), password policy,
 * institutional roles, immutable email, self-deactivation and
 * last-administrator guards. Semantic failures throw 422
 * ValidationException; the database constraints (users.email UNIQUE,
 * person_id UNIQUE) remain the final backstop. Persistence is
 * delegated to the repository port, so the service stays
 * unit-testable without a database (ADR-11).
 */
final class UserService implements UserServiceInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly ClockInterface $clock,
        private readonly PasswordPolicy $password,
    ) {}

    public function find(int $userId): ?User
    {
        return $this->users->findById($userId);
    }

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

    public function createUser(string $name, string $email, string $password, array $roles): User
    {
        if ($this->users->emailTaken($email)) {
            throw ValidationException::withMessages([
                'email' => 'The email is already taken (active or deactivated accounts reserve it).',
            ]);
        }

        $this->guardPasswordPolicy($password);
        $this->guardRoles($roles);

        return $this->users->createUser($name, $email, $password, $roles, $this->clock->now());
    }

    public function updateUser(int $userId, ?string $name, ?array $roles, ?string $email = null): ?User
    {
        $user = $this->users->findById($userId);

        if ($user === null) {
            return null;
        }

        if ($email !== null && strcasecmp($email, (string) $user->email) !== 0) {
            throw ValidationException::withMessages([
                'email' => 'The email is immutable for an existing account.',
            ]);
        }

        if ($roles !== null) {
            $this->guardRoles($roles);
            $this->guardNotLastAdministrator($user, $roles);
        }

        return $this->users->updateUser($user, $name, $roles);
    }

    public function deactivate(int $userId, int $actingUserId): ?User
    {
        $user = $this->users->findById($userId);

        if ($user === null) {
            return null;
        }

        if ((int) $user->id === $actingUserId) {
            throw ValidationException::withMessages([
                'id' => 'You cannot deactivate your own account.',
            ]);
        }

        // Deactivation removes the account from the active admin pool
        // just like a role removal would.
        $this->guardNotLastAdministrator($user, null);

        $this->users->deactivate($user);

        return $user;
    }

    public function restore(int $userId): ?User
    {
        $user = $this->users->findByIdIncludingDeactivated($userId);

        if ($user === null) {
            return null;
        }

        if ($user->deleted_at === null) {
            // Idempotent: the account is already active.
            return $user;
        }

        return $this->users->restore($user);
    }

    public function unlock(int $userId): ?User
    {
        $user = $this->users->findById($userId);

        if ($user === null) {
            return null;
        }

        if ($user->locked_at === null && (int) $user->failed_login_attempts === 0) {
            // Idempotent: a clean account produces no audited write.
            return $user;
        }

        return $this->users->unlock($user);
    }

    public function resetPassword(int $userId, string $password): ?User
    {
        $user = $this->users->findById($userId);

        if ($user === null) {
            return null;
        }

        $this->guardPasswordPolicy($password);

        $user = $this->users->resetPassword($user, $password, $this->clock->now());
        $this->users->revokeAllTokens($user);

        return $user;
    }

    /**
     * @param  array{q?: string, role?: string, status?: string}  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->users->search($filters, $page, $perPage);
    }

    /**
     * Defense in depth: the FormRequest validates against the same
     * catalog, but the service re-checks so any caller gets the
     * guarantee. Roles outside the PermissionMatrix can never enter
     * the pivot tables.
     *
     * @param  list<string>  $roles
     */
    private function guardRoles(array $roles): void
    {
        $unknown = array_diff($roles, PermissionMatrix::roles());

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'roles' => sprintf(
                    'Unknown institutional roles: %s. Valid roles: %s.',
                    implode(', ', $unknown),
                    implode(', ', PermissionMatrix::roles()),
                ),
            ]);
        }
    }

    /**
     * Keeps at least one ACTIVE administrator in the system: the
     * guard fires when the target holds the admin role today and the
     * operation (role sync or deactivation) would drop it.
     *
     * @param  list<string>|null  $newRoles
     */
    private function guardNotLastAdministrator(User $user, ?array $newRoles): void
    {
        if (! in_array('admin', $this->users->roleNamesOf($user), true)) {
            return;
        }

        if ($newRoles !== null && in_array('admin', $newRoles, true)) {
            return;
        }

        if ($this->users->countActiveAdministrators($user) === 0) {
            throw ValidationException::withMessages([
                'roles' => 'The system must keep at least one active administrator.',
            ]);
        }
    }

    private function guardPasswordPolicy(string $password): void
    {
        if ($this->password->violations($password) !== []) {
            throw ValidationException::withMessages([
                'password' => 'The password does not satisfy the password policy (length/complexity).',
            ]);
        }
    }
}
