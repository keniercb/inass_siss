<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\Contracts;

use App\Modules\Security\Infrastructure\Persistence\Models\Role;
use Illuminate\Support\Collection;

/**
 * Persistence port for the Security role aggregate (RF-SEG-002,
 * ADR-26).
 *
 * Declared in the Application layer so use cases depend on the
 * abstraction (DIP, architecture doc section 7) while the Eloquent
 * implementation stays confined to Infrastructure. Swapping the data
 * store or injecting an in-memory fake in tests never touches the
 * business logic.
 *
 * Audit semantics (ADR-19/ADR-26): row writes (create, profile
 * edition, delete) persist through the observers watching Role and
 * land in the append-only bitácora; permission pivots — invisible
 * to Eloquent model events — get an explicit AuditRecorder entry
 * with the previous grant set, mirroring the role-assignment trail
 * of the user repository.
 */
interface RoleRepositoryInterface
{
    /**
     * Every role of the directory with its permission set and the
     * count of accounts holding it (the users_count projection).
     *
     * @return Collection<int, Role>
     */
    public function all(): Collection;

    /**
     * Returns the role with the given id, or null when it does not
     * exist.
     */
    public function find(int $roleId): ?Role;

    /**
     * Whether a role with the given name exists (any kind:
     * institutional or custom). Natural-key reservation for the
     * roles name+guard UNIQUE constraint (RN-008).
     */
    public function nameExists(string $name, ?int $exceptId = null): bool;

    /**
     * Number of accounts holding the role through the spatie pivot
     * (deactivation of an account does not release the pivot, so the
     * count spans soft-deleted accounts too).
     */
    public function usersCount(int $roleId): int;

    /**
     * Creates a custom role with its permission set. Audited (row
     * created event + explicit permissions entry).
     *
     * @param  list<string>  $permissions
     */
    public function create(string $name, ?string $description, array $permissions): Role;

    /**
     * Edits the display fields and/or replaces the whole permission
     * set. Audited (row updated event + explicit permissions entry
     * with the previous grant when it changes).
     *
     * @param  list<string>|null  $permissions
     */
    public function update(Role $role, ?string $name, ?string $description, ?array $permissions): Role;

    /**
     * Deletes the role. Audited (row deleted event + explicit entry
     * preserving the permission set at deletion time — the pivots go
     * away with the row).
     */
    public function delete(Role $role): void;
}
