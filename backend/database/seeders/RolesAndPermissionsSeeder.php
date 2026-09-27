<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Security\Domain\Authorization\PermissionMatrix;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Materializes the PermissionMatrix domain value into the
 * spatie/laravel-permission tables (RF-SEG-002, S3.4, ADR-05/ADR-18).
 *
 * Idempotent by construction: permissions and roles upsert by their
 * natural key (name + guard) and each role syncs its grants against
 * the matrix, so re-running converges the tables exactly — runtime
 * grants not present in the matrix are intentionally reset, because
 * the matrix is the single source of truth until fase 6 ships the
 * management UI.
 */
final class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionMatrix::permissions() as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        foreach (PermissionMatrix::roles() as $roleName) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ]);

            $role->syncPermissions(PermissionMatrix::permissionsFor($roleName));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
