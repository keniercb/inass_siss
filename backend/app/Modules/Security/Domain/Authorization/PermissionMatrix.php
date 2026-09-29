<?php

declare(strict_types=1);

namespace App\Modules\Security\Domain\Authorization;

use InvalidArgumentException;

/**
 * Single source of truth for the initial role-permission matrix
 * (RF-SEG-002, S3.4, ADR-18).
 *
 * The five institutional roles come from section 2.2 of the
 * requirements: administrador (technical staff, everything),
 * director (approves cases: read-only consultation until the
 * review/approve permissions arrive with S6, then the exclusive
 * approval), especialista (case worker: consultation until S6,
 * then the review surface), operador (data capture: registers
 * people and cases in estado Solicitud with their subrecords) and
 * auditor (strictly read-only plus the audit trail, with cases.view
 * to cross-read the subjects of the bitácora — same precedent as
 * people.view and users.view).
 *
 * The matrix is deliberately a pure domain value: the seeder
 * materializes it into spatie/laravel-permission tables, the Pest
 * suite executes it as a dataset (each cell is one acceptance
 * criterion) and fase 6 extends it to the complete matrix without
 * touching consumer code. Permission names follow the architecture
 * convention "modulo.accion" (ver, crear, editar, aprobar, exportar).
 */
final class PermissionMatrix
{
    /**
     * Institutional roles (section 2.2). Order is part of the seed
     * output stability, not of any business meaning.
     */
    private const array ROLES = [
        'admin',
        'director',
        'specialist',
        'operator',
        'auditor',
    ];

    /**
     * Initial permission catalog for the modules that exist today
     * (Catalogs, Settings, People, audit trail, user management, the
     * Fase 2 organizational and legal structure, the Fase 3 case
     * aggregate and the role management surface itself); pensioners/
     * payments/reports join in their own phases. Alphabetical on
     * purpose: the matrix stays diff-friendly.
     */
    private const array PERMISSIONS = [
        'audit.export',
        'audit.view',
        'cases.create',
        'cases.edit',
        'cases.view',
        'catalogs.manage',
        'catalogs.view',
        'legalbases.manage',
        'legalbases.view',
        'organizations.manage',
        'organizations.view',
        'people.create',
        'people.delete',
        'people.edit',
        'people.view',
        'roles.manage',
        'roles.view',
        'settings.manage',
        'settings.view',
        'users.manage',
        'users.view',
    ];

    /**
     * Role => granted permissions. Derived from the section 2.2 usage
     * table; admin holds everything by definition.
     */
    private const array GRANTS = [
        'admin' => [
            'audit.export', 'audit.view',
            'cases.create', 'cases.edit', 'cases.view',
            'catalogs.manage', 'catalogs.view',
            'legalbases.manage', 'legalbases.view',
            'organizations.manage', 'organizations.view',
            'people.create', 'people.delete', 'people.edit', 'people.view',
            'roles.manage', 'roles.view',
            'settings.manage', 'settings.view',
            'users.manage', 'users.view',
        ],
        'director' => [
            'cases.view',
            'catalogs.view',
            'legalbases.view',
            'organizations.view',
            'people.view',
            'settings.view',
        ],
        'specialist' => [
            'cases.view',
            'catalogs.view',
            'legalbases.view',
            'organizations.view',
            'people.view',
            'settings.view',
        ],
        'operator' => [
            'cases.create', 'cases.edit', 'cases.view',
            'catalogs.view',
            'legalbases.view',
            'organizations.view',
            'people.create', 'people.edit', 'people.view',
        ],
        'auditor' => [
            'audit.export', 'audit.view',
            'cases.view',
            'catalogs.view',
            'legalbases.view',
            'organizations.view',
            'people.view',
            'roles.view',
            'settings.view',
            // Account directory read (ADR-24): the Auditor resolves
            // bitácora causers (who acted) and needs the account
            // surface to cross-read; strictly read-only (solo lectura,
            // sección 2.2) — never users.manage.
            'users.view',
        ],
    ];

    /** @return list<non-empty-string> */
    public static function roles(): array
    {
        return self::ROLES;
    }

    /** @return list<non-empty-string> */
    public static function permissions(): array
    {
        return self::PERMISSIONS;
    }

    /**
     * Permissions granted to one role. Unknown roles fail loudly:
     * a typo in role wiring is a developer error, never a silent
     * empty grant.
     *
     * @return list<non-empty-string>
     */
    public static function permissionsFor(string $role): array
    {
        if (! isset(self::GRANTS[$role])) {
            throw new InvalidArgumentException(
                sprintf('Unknown role [%s]: expected one of [%s].', $role, implode(', ', self::ROLES)),
            );
        }

        return self::GRANTS[$role];
    }

    public static function roleHasPermission(string $role, string $permission): bool
    {
        return in_array($permission, self::permissionsFor($role), true);
    }

    /**
     * Full matrix as test dataset rows: every role x permission pair
     * with its expected grant (S3.4: "matriz rol-permiso como dataset
     * de Pest"). Fase 6 extends the matrix and the dataset follows
     * automatically.
     *
     * @return list<array{string, string, bool}>
     */
    public static function dataset(): array
    {
        $rows = [];

        foreach (self::ROLES as $role) {
            $granted = self::GRANTS[$role];

            foreach (self::PERMISSIONS as $permission) {
                $rows[] = [$role, $permission, in_array($permission, $granted, true)];
            }
        }

        return $rows;
    }
}
