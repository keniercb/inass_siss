<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\Services;

use App\Modules\Security\Application\Contracts\RoleRepositoryInterface;
use App\Modules\Security\Application\Contracts\RoleServiceInterface;
use App\Modules\Security\Application\Exceptions\RoleInUseException;
use App\Modules\Security\Domain\Authorization\PermissionMatrix;
use App\Modules\Security\Domain\Authorization\RoleName;
use App\Modules\Security\Infrastructure\Persistence\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Role management use cases (RF-SEG-002, ADR-26).
 *
 * Every rule is decided here (defense in depth): the RoleName slug
 * contract, the reservation of the institutional names, the
 * permission-subset rule against the PermissionMatrix catalog and
 * the natural-key uniqueness (roles name+guard UNIQUE, RN-008). The
 * five institutional roles of section 2.2 are immutable through this
 * surface because the matrix is their single source of truth — fase
 * 6 extends them in code, never in the database. Semantic failures
 * throw 422 ValidationException; deleting a role that accounts still
 * hold throws RoleInUseException for the conversational 409.
 *
 * Persistence is delegated to the repository port, so the service
 * stays unit-testable without a database (ADR-11).
 */
final class RoleService implements RoleServiceInterface
{
    public function __construct(
        private readonly RoleRepositoryInterface $roles,
    ) {}

    public function list(): Collection
    {
        return $this->roles->all();
    }

    public function find(int $roleId): ?Role
    {
        return $this->roles->find($roleId);
    }

    public function create(string $name, ?string $description, array $permissions): Role
    {
        $this->assertNameIsValid($name);
        $this->assertNameIsNotReserved($name);
        $this->assertNameIsAvailable($name, null);
        $this->assertPermissionsAreAssignable($permissions);

        return $this->roles->create($name, $description, array_values(array_unique($permissions)));
    }

    public function update(int $roleId, ?string $name, ?string $description, ?array $permissions): ?Role
    {
        $role = $this->roles->find($roleId);

        if ($role === null) {
            return null;
        }

        $this->assertRoleIsCustom($role);

        if ($name !== null && $name !== $role->name) {
            $this->assertNameIsValid($name);
            $this->assertNameIsAvailable($name, (int) $role->id);
        }

        if ($permissions !== null) {
            $this->assertPermissionsAreAssignable($permissions);
        }

        return $this->roles->update(
            $role,
            $name,
            $description,
            $permissions === null ? null : array_values(array_unique($permissions)),
        );
    }

    public function delete(int $roleId): bool
    {
        $role = $this->roles->find($roleId);

        if ($role === null) {
            return false;
        }

        $this->assertRoleIsCustom($role);

        $holders = $this->roles->usersCount($roleId);

        if ($holders > 0) {
            throw new RoleInUseException($roleId, $holders);
        }

        $this->roles->delete($role);

        return true;
    }

    /**
     * The name must satisfy the RoleName slug contract shared with
     * the FormRequest rule (defense in depth).
     */
    private function assertNameIsValid(string $name): void
    {
        try {
            RoleName::fromString($name);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'name' => 'The role name must be a lowercase slug of 2-31 characters (letters, digits, underscores) starting with a letter.',
            ]);
        }
    }

    /**
     * Institutional names are permanently reserved: the five roles
     * of section 2.2 are code-owned policy materialized from the
     * PermissionMatrix, and the management surface must never shadow
     * them with a divergent grant set.
     */
    private function assertNameIsNotReserved(string $name): void
    {
        if (RoleName::isReserved($name)) {
            throw ValidationException::withMessages([
                'name' => 'The name belongs to an institutional role (section 2.2) and is reserved for the permission matrix.',
            ]);
        }
    }

    /**
     * Natural-key reservation for the roles name+guard UNIQUE
     * constraint (RN-008): the semantic 422 answers before the
     * database backstop fires.
     */
    private function assertNameIsAvailable(string $name, ?int $exceptId): void
    {
        if ($this->roles->nameExists($name, $exceptId)) {
            throw ValidationException::withMessages([
                'name' => 'A role with this name already exists.',
            ]);
        }
    }

    /**
     * Custom roles bundle a subset of the matrix catalog: anything
     * outside it (a permission of a module that has not shipped, a
     * typo) answers 422 with the offending entries so the caller can
     * correct the payload. A role must hold at least one permission
     * — an empty bundle has no reason to exist.
     *
     * @param  list<string>  $permissions
     */
    private function assertPermissionsAreAssignable(array $permissions): void
    {
        if ($permissions === []) {
            throw ValidationException::withMessages([
                'permissions' => 'The role must hold at least one permission.',
            ]);
        }

        $unknown = array_values(array_diff($permissions, PermissionMatrix::permissions()));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'permissions' => sprintf(
                    'Unknown permissions: %s. Valid catalog: %s.',
                    implode(', ', $unknown),
                    implode(', ', PermissionMatrix::permissions()),
                ),
            ]);
        }
    }

    /**
     * Institutional roles are immutable through the management
     * surface: their grants live in the PermissionMatrix (single
     * source of truth until fase 6 ships the full-matrix edition in
     * code), so renaming, re-granting or deleting one answers 422
     * without touching the database.
     */
    private function assertRoleIsCustom(Role $role): void
    {
        if ((bool) $role->is_system) {
            throw ValidationException::withMessages([
                'name' => 'Institutional roles (section 2.2) are immutable: their permissions live in the permission matrix, not in the database.',
            ]);
        }
    }
}
