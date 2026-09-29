<?php

declare(strict_types=1);

namespace App\Modules\Security\Infrastructure\Persistence;

use App\Modules\Security\Application\Contracts\PermissionUsageQueryInterface;
use App\Modules\Security\Infrastructure\Persistence\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent implementation of the permission usage query port.
 *
 * The single place in the Security module allowed to read the
 * spatie pivots for the catalog projections (architecture doc
 * section 6: Eloquent is confined to Infrastructure; ADR-11).
 *
 * Role grants live in role_has_permissions (a plain role_id
 * permission_id pivot) and account assignments in model_has_roles —
 * only users hold roles in this application, so the account count
 * groups by role without a model_type filter, mirroring the
 * usersCount projection of the role repository. Deactivation never
 * releases the pivots (ADR-24): deactivated accounts keep counting,
 * which is exactly the reservation the role directory projects.
 */
final class EloquentPermissionUsageQuery implements PermissionUsageQueryInterface
{
    public function customRolesByPermission(): array
    {
        $map = [];

        $roles = Role::query()
            ->where('is_system', false)
            ->with('permissions')
            ->get();

        foreach ($roles as $role) {
            foreach ($role->permissions->pluck('name') as $permission) {
                $map[(string) $permission][] = (string) $role->name;
            }
        }

        return $map;
    }

    public function usersCountByPermission(): array
    {
        $tables = config('permission.table_names');

        assert(is_array($tables));

        $permissionsTable = $tables['permissions'] ?? null;
        $roleGrantsTable = $tables['role_has_permissions'] ?? null;
        $roleHoldersTable = $tables['model_has_roles'] ?? null;

        assert(is_string($permissionsTable) && $permissionsTable !== '');
        assert(is_string($roleGrantsTable) && $roleGrantsTable !== '');
        assert(is_string($roleHoldersTable) && $roleHoldersTable !== '');

        // One grouped query: permission -> distinct accounts holding
        // ANY role that grants it. A account stacked on several roles
        // granting the same permission counts once.
        $rows = DB::table($roleGrantsTable.' as rhp')
            ->join($roleHoldersTable.' as mhr', 'mhr.role_id', '=', 'rhp.role_id')
            ->join($permissionsTable.' as p', 'p.id', '=', 'rhp.permission_id')
            ->groupBy('p.name')
            ->selectRaw('p.name as permission_name, COUNT(DISTINCT mhr.model_id) as accounts')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->permission_name] = (int) $row->accounts;
        }

        return $counts;
    }
}
