<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Security\Domain\Authorization\PermissionMatrix;
use App\Modules\Security\Infrastructure\Persistence\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Materializes the PermissionMatrix domain value into the
 * spatie/laravel-permission tables (RF-SEG-002, S3.4, ADR-05/ADR-18,
 * ADR-26).
 *
 * Idempotent by construction: permissions and roles upsert by their
 * natural key (name + guard) and each institutional role syncs its
 * grants against the matrix, so re-running converges the tables
 * exactly — runtime grants not present in the matrix are
 * intentionally reset, because the matrix remains the single source
 * of truth for the five institutional roles of section 2.2 (their
 * is_system flag marks them immutable through the management API).
 * Custom roles created through the management surface are NOT part
 * of the matrix and are never touched here.
 */
final class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Institutional descriptions (section 2.2 of the requirements):
     * what each role is FOR, shown by the role directory.
     *
     * @var array<string, string>
     */
    private const array DESCRIPTIONS = [
        'admin' => 'Personal técnico del Ministerio: gestiona usuarios, roles, catálogos y configuración (sección 2.2).',
        'director' => 'Director de oficina territorial: consulta los expedientes y aprueba (aprobación exclusiva, llega con S6).',
        'specialist' => 'Especialista de trámite: revisa los expedientes en estado Revisión (superficie con S6).',
        'operator' => 'Operador de captura: registra personas y expedientes en estado Solicitud.',
        'auditor' => 'Personal de control interno: estrictamente solo lectura y bitácoras.',
    ];

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

            $role->forceFill([
                'is_system' => true,
                'description' => self::DESCRIPTIONS[$roleName],
            ])->save();

            $role->syncPermissions(PermissionMatrix::permissionsFor($roleName));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
