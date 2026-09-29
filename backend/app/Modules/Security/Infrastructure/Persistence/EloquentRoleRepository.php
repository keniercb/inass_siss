<?php

declare(strict_types=1);

namespace App\Modules\Security\Infrastructure\Persistence;

use App\Modules\Security\Application\Contracts\RoleRepositoryInterface;
use App\Modules\Security\Infrastructure\Persistence\Models\Role;
use App\Modules\Shared\Support\AuditRecorder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent implementation of the role repository port.
 *
 * The single place in the Security module allowed to touch the
 * spatie roles table and its pivots (architecture doc section 6:
 * Eloquent is confined to Infrastructure; ADR-11).
 *
 * Audit semantics (ADR-19/ADR-26): row writes go through the normal
 * save pipeline so the observers watching Role land them in the
 * bitácora; permission pivots — invisible to Eloquent events — get
 * an explicit AuditRecorder entry with the previous grant set, and
 * the deletion preserves the permission set the row carried because
 * the cascading pivots go away with it.
 */
final class EloquentRoleRepository implements RoleRepositoryInterface
{
    public function __construct(
        private readonly AuditRecorder $audit,
    ) {}

    public function all(): Collection
    {
        // The count mirrors the deletion guard (raw pivot): a
        // deactivated account keeps reserving its role, so the
        // directory and the 409 never disagree about the number.
        return Role::query()
            ->with('permissions')
            ->withCount(['users' => fn ($builder) => $builder->withTrashed()])
            ->orderBy('name')
            ->get();
    }

    public function find(int $roleId): ?Role
    {
        /** @var Role|null $role */
        $role = Role::query()
            ->with('permissions')
            ->withCount(['users' => fn ($builder) => $builder->withTrashed()])
            ->find($roleId);

        return $role;
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        return Role::query()
            ->where('name', $name)
            ->where('guard_name', 'web')
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function usersCount(int $roleId): int
    {
        // Raw pivot count: deactivation does not release the pivot,
        // so the deletion guard must see soft-deleted holders too —
        // exactly like the users.email reservation spans deactivated
        // accounts (ADR-24).
        $table = config('permission.table_names.model_has_roles');

        assert(is_string($table) && $table !== '');

        return (int) DB::table($table)
            ->where('role_id', $roleId)
            ->count();
    }

    public function create(string $name, ?string $description, array $permissions): Role
    {
        $role = Role::create([
            'name' => $name,
            'guard_name' => 'web',
            'description' => $description,
            'is_system' => false,
        ]);

        $this->syncPermissionsAndAudit($role, $permissions, initial: true);

        return $role;
    }

    public function update(Role $role, ?string $name, ?string $description, ?array $permissions): Role
    {
        $dirty = false;

        if ($name !== null && $name !== $role->name) {
            $role->name = $name;
            $dirty = true;
        }

        if ($description !== null && $description !== $role->description) {
            $role->description = $description;
            $dirty = true;
        }

        if ($dirty) {
            $role->save();
        }

        if ($permissions !== null) {
            $this->syncPermissionsAndAudit($role, $permissions, initial: false);
        }

        return $role;
    }

    public function delete(Role $role): void
    {
        $permissions = $role->permissions->pluck('name')->sort()->values()->all();

        // The permission set dies with the cascading pivots: the
        // trail keeps it alongside the name so the Auditor can still
        // answer "what could this role do" years later.
        $this->audit->record($role, 'deleted', 'deleted', [
            'name' => $role->name,
            'permissions' => $permissions,
        ], []);

        $role->delete();
    }

    /**
     * Syncs the spatie permission grants and records an explicit
     * trail entry with the PREVIOUS set: the pivot write is invisible
     * to Eloquent model events, so without this entry a permission
     * change — a critical security edit — would escape the bitácora
     * (same pattern as the role-assignment trail of the user
     * repository).
     *
     * @param  list<string>  $permissions
     */
    private function syncPermissionsAndAudit(Role $role, array $permissions, bool $initial): void
    {
        $previous = $role->permissions->pluck('name')->sort()->values()->all();

        $role->syncPermissions($permissions);

        $assigned = $role->refresh()->permissions->pluck('name')->sort()->values()->all();

        if ($initial && $previous === []) {
            // Creation: the created event already carries the row; the
            // permissions entry documents the initial grant on its own.
            $this->audit->record($role, 'updated', 'updated', [], ['permissions' => $assigned]);

            return;
        }

        if ($assigned !== $previous) {
            $this->audit->record($role, 'updated', 'updated', ['permissions' => $previous], ['permissions' => $assigned]);
        }
    }
}
