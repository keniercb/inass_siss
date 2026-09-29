<?php

declare(strict_types=1);

namespace App\Modules\Security\Tests\Fakes;

use App\Modules\Security\Application\Contracts\RoleRepositoryInterface;
use App\Modules\Security\Infrastructure\Persistence\Models\Role;
use Illuminate\Support\Collection;

/**
 * In-memory stand-in for the role repository port (ADR-11, ADR-26):
 * lets the RoleService unit suite run without a database or the
 * container while recording every call so the tests can assert the
 * interactions.
 *
 * Roles are unsaved Eloquent instances (constructor-safe once the
 * minimal RBAC container of the test seam is bound): permissions and
 * user counts live in parallel maps the tests control directly,
 * mirroring the pivot reads of the Eloquent adapter without touching
 * spatie tables.
 */
final class InMemoryRoleRepository implements RoleRepositoryInterface
{
    /** @var array<int, Role> */
    public array $roles = [];

    /** @var array<int, list<string>> */
    public array $permissions = [];

    /** @var array<int, int> */
    public array $userCounts = [];

    public int $deletions = 0;

    public ?Role $lastCreated = null;

    /** @var array<int, array{0: string|null, 1: string|null, 2: list<string>|null}> */
    public array $updates = [];

    private int $nextId = 1;

    /**
     * Registers a hand-built role (id assigned when absent) with its
     * permission set and the number of accounts holding it.
     *
     * @param  list<string>  $permissions
     */
    public function seed(Role $role, array $permissions = [], int $users = 0): Role
    {
        if ((int) $role->id === 0) {
            $role->id = $this->nextId;
        }

        $this->nextId = max($this->nextId, (int) $role->id + 1);
        $this->roles[(int) $role->id] = $role;
        $this->permissions[(int) $role->id] = $permissions;
        $this->userCounts[(int) $role->id] = $users;

        return $role;
    }

    public function all(): Collection
    {
        return new Collection(array_values($this->roles));
    }

    public function find(int $roleId): ?Role
    {
        return $this->roles[$roleId] ?? null;
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        foreach ($this->roles as $id => $role) {
            if ($role->name === $name && ($exceptId === null || $id !== $exceptId)) {
                return true;
            }
        }

        return false;
    }

    public function usersCount(int $roleId): int
    {
        return $this->userCounts[$roleId] ?? 0;
    }

    public function create(string $name, ?string $description, array $permissions): Role
    {
        $role = new Role(['name' => $name, 'guard_name' => 'web']);
        $role->forceFill(['description' => $description, 'is_system' => false]);
        $role->id = $this->nextId++;

        $this->roles[(int) $role->id] = $role;
        $this->permissions[(int) $role->id] = $permissions;
        $this->userCounts[(int) $role->id] = 0;
        $this->lastCreated = $role;

        return $role;
    }

    public function update(Role $role, ?string $name, ?string $description, ?array $permissions): Role
    {
        if ($name !== null && $name !== $role->name) {
            $role->name = $name;
        }

        if ($description !== null && $description !== $role->description) {
            $role->description = $description;
        }

        if ($permissions !== null) {
            $this->permissions[(int) $role->id] = $permissions;
        }

        $this->updates[(int) $role->id] = [$name, $description, $permissions];

        return $role;
    }

    public function delete(Role $role): void
    {
        $this->deletions++;
        unset(
            $this->roles[(int) $role->id],
            $this->permissions[(int) $role->id],
            $this->userCounts[(int) $role->id],
        );
    }
}
