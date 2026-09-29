<?php

declare(strict_types=1);

namespace App\Modules\Security\Tests\Fakes;

use App\Modules\Security\Application\Contracts\UserRepositoryInterface;
use App\Modules\Security\Domain\Authentication\LockoutState;
use App\Modules\Security\Domain\Authorization\PermissionMatrix;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use DateTimeImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as ConcretePaginator;

/**
 * In-memory stand-in for the user repository port (ADR-11): lets the
 * Security unit suites run without a database or the container and
 * records every call so the tests can assert the interactions.
 *
 * The password cast still applies (the model hashes on assignment),
 * so accounts created through the fake authenticate with the same
 * semantics as the Eloquent adapter. Soft-deleted accounts keep
 * reserving their email and person, mirroring the real UNIQUE scope.
 */
final class InMemoryUserRepository implements UserRepositoryInterface
{
    /** @var array<int, User> */
    public array $users = [];

    /** @var array<int, true> */
    public array $deactivated = [];

    /** @var array<int, list<string>> */
    public array $roles = [];

    /** @var list<string> */
    public array $extraKnownRoles = [];

    public int $issuedTokens = 0;

    public bool $revokedCurrentToken = false;

    public int $revokedAllTokens = 0;

    public int $revokedOtherTokens = 0;

    public int $clearedFailures = 0;

    public int $unlocked = 0;

    public int $restored = 0;

    /** @var list<LockoutState> */
    public array $recordedFailures = [];

    /** @var array<int, string> plain-text passwords by user id */
    public array $resetPasswords = [];

    public ?User $lastCreated = null;

    private int $nextId = 1;

    /**
     * Registers a hand-built account (id assigned when absent) with
     * its roles, replacing the old single $stored slot.
     *
     * @param  list<string>  $roles
     */
    public function seed(User $user, array $roles = []): User
    {
        // Bare (unsaved) models report id 0 once cast: assign the next
        // sequence number to any model the test did not build with one.
        if ((int) $user->id === 0) {
            $user->id = $this->nextId;
        }

        $this->nextId = max($this->nextId, (int) $user->id + 1);
        $this->users[(int) $user->id] = $user;
        $this->roles[(int) $user->id] = $roles;

        return $user;
    }

    public function findByEmail(string $email): ?User
    {
        foreach ($this->users as $user) {
            if ($user->email === $email && ! isset($this->deactivated[(int) $user->id])) {
                return $user;
            }
        }

        return null;
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
        if (isset($this->users[$id]) && ! isset($this->deactivated[$id])) {
            return $this->users[$id];
        }

        return null;
    }

    public function findByIdIncludingDeactivated(int $id): ?User
    {
        return $this->users[$id] ?? null;
    }

    public function findOwnerOfPerson(int $personId): ?User
    {
        foreach ($this->users as $user) {
            if ($user->person_id === $personId) {
                return $user;
            }
        }

        return null;
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

    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $needle = mb_strtolower((string) ($filters['q'] ?? ''));
        $role = $filters['role'] ?? null;
        $status = $filters['status'] ?? 'active';

        $matches = array_filter($this->users, function (User $user) use ($needle, $role, $status): bool {
            $deleted = isset($this->deactivated[(int) $user->id]);

            if ($status === 'active' && $deleted) {
                return false;
            }

            if ($status === 'inactive' && ! $deleted) {
                return false;
            }

            if ($role !== null && ! in_array($role, $this->roles[(int) $user->id] ?? [], true)) {
                return false;
            }

            if ($needle !== '' && ! str_contains(mb_strtolower((string) $user->name), $needle)
                && ! str_contains(mb_strtolower((string) $user->email), $needle)) {
                return false;
            }

            return true;
        });

        $page = max(1, $page);

        $items = array_values($matches);

        return new ConcretePaginator(
            array_slice($items, ($page - 1) * $perPage, $perPage),
            count($items),
            $perPage,
            $page,
        );
    }

    public function emailTaken(string $email): bool
    {
        foreach ($this->users as $user) {
            if ($user->email === $email) {
                return true;
            }
        }

        return false;
    }

    public function createUser(string $name, string $email, string $password, array $roles, DateTimeImmutable $passwordChangedAt): User
    {
        $user = new User;
        $user->name = $name;
        $user->email = $email;
        $user->password = $password;
        $user->password_changed_at = $passwordChangedAt;
        $user->id = $this->nextId;

        $this->lastCreated = $user;

        return $this->seed($user, $roles);
    }

    public function updateUser(User $user, ?string $name, ?array $roles): User
    {
        if ($name !== null) {
            $user->name = $name;
        }

        if ($roles !== null) {
            $this->roles[(int) $user->id] = $roles;
        }

        return $user;
    }

    public function deactivate(User $user): void
    {
        $this->deactivated[(int) $user->id] = true;
        // Mirrors the real soft delete so restore() sees a deleted row.
        $user->deleted_at = new DateTimeImmutable('2000-01-01 00:00:00');
        $this->revokedAllTokens++;
    }

    public function restore(User $user): User
    {
        unset($this->deactivated[(int) $user->id]);
        $user->deleted_at = null;
        $this->restored++;

        return $user;
    }

    public function unlock(User $user): User
    {
        $this->unlocked++;
        $user->failed_login_attempts = 0;
        $user->locked_at = null;

        return $user;
    }

    public function recordFailedAttempt(User $user, LockoutState $state): void
    {
        $user->failed_login_attempts = $state->failedAttempts;
        $user->locked_at = $state->lockedAt;
        $this->recordedFailures[] = $state;
    }

    public function clearLoginFailures(User $user): void
    {
        $this->clearedFailures++;
        $user->failed_login_attempts = 0;
        $user->locked_at = null;
    }

    public function resetPassword(User $user, string $password, DateTimeImmutable $changedAt): User
    {
        $user->password = $password;
        $user->password_changed_at = $changedAt;
        $user->failed_login_attempts = 0;
        $user->locked_at = null;
        $this->resetPasswords[(int) $user->id] = $password;

        return $user;
    }

    public function revokeAllTokens(User $user): void
    {
        $this->revokedAllTokens++;
    }

    public function revokeOtherTokens(User $user): void
    {
        $this->revokedOtherTokens++;
    }

    public function roleNamesOf(User $user): array
    {
        return $this->roles[(int) $user->id] ?? [];
    }

    /**
     * The fake resolves the catalog from the institutional matrix
     * (seeded in every environment) plus the roles registered through
     * seed() and any extra names the test promotes with
     * recognizeRole(): unit suites control the directory exactly like
     * the database would (ADR-26).
     *
     * @param  list<string>  $roles
     * @return list<string>
     */
    public function unknownRoles(array $roles): array
    {
        $known = array_merge(PermissionMatrix::roles(), $this->extraKnownRoles);

        foreach ($this->roles as $assigned) {
            $known = array_merge($known, $assigned);
        }

        return array_values(array_diff($roles, array_unique($known)));
    }

    /**
     * Registers a role name as existing in the directory without
     * wiring it to any account.
     */
    public function recognizeRole(string $name): void
    {
        $this->extraKnownRoles[] = $name;
    }

    public function countActiveAdministrators(User $except): int
    {
        $count = 0;

        foreach ($this->users as $user) {
            if ((int) $user->id === (int) $except->id) {
                continue;
            }

            if (isset($this->deactivated[(int) $user->id])) {
                continue;
            }

            if (in_array('admin', $this->roles[(int) $user->id] ?? [], true)) {
                $count++;
            }
        }

        return $count;
    }
}
