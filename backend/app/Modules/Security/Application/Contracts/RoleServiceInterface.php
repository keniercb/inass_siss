<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\Contracts;

use App\Modules\Security\Application\Exceptions\RoleInUseException;
use App\Modules\Security\Infrastructure\Persistence\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Role management use cases (RF-SEG-002, ADR-26).
 *
 * The institutional roles of section 2.2 are immutable policy
 * materialized from the PermissionMatrix: the service rejects any
 * write against them with a 422-semantic ValidationException. Custom
 * roles bundle a subset of the permission catalog and are fully
 * manageable; deleting one that accounts still hold throws
 * RoleInUseException so the HTTP layer can answer the conversational
 * 409.
 */
interface RoleServiceInterface
{
    /**
     * Directory of roles (institutional + custom) with permission
     * sets and account counts.
     *
     * @return Collection<int, Role>
     */
    public function list(): Collection;

    /**
     * Returns the role with the given id, or null when it does not
     * exist.
     */
    public function find(int $roleId): ?Role;

    /**
     * Creates a custom role.
     *
     * @param  list<string>  $permissions
     *
     * @throws ValidationException invalid name, reserved institutional name, taken name or permissions outside the catalog
     */
    public function create(string $name, ?string $description, array $permissions): Role;

    /**
     * Edits a custom role (partial PATCH semantics: null leaves the
     * field untouched; a permissions array replaces the whole set).
     * Returns null when the role does not exist.
     *
     * @param  list<string>|null  $permissions
     *
     * @throws ValidationException unknown id aside: institutional role, invalid/taken name or permissions outside the catalog
     */
    public function update(int $roleId, ?string $name, ?string $description, ?array $permissions): ?Role;

    /**
     * Deletes an unused custom role. Returns false when the role does
     * not exist.
     *
     * @throws RoleInUseException when accounts still hold the role
     * @throws ValidationException when the role is institutional (immutable)
     */
    public function delete(int $roleId): bool;
}
