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
 * director (approves cases, read-only consultation until the
 * EXP/CAL/REP permissions arrive with their phases), especialista
 * (case worker, consultation), operador (data capture, registers
 * people) and auditor (strictly read-only plus the audit trail).
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
     * (Catalogs, Settings, People, audit trail and user management);
     * cases/pensioners/payments/reports join in their own phases.
     * Alphabetical on purpose: the matrix stays diff-friendly.
     */
    private const array PERMISSIONS = [
        'audit.export',
        'audit.view',
        'catalogs.manage',
        'catalogs.view',
        'people.create',
        'people.delete',
        'people.edit',
        'people.view',
        'settings.manage',
        'settings.view',
        'users.manage',
    ];

    /**
     * Role => granted permissions. Derived from the section 2.2 usage
     * table; admin holds everything by definition.
     */
    private const array GRANTS = [
        'admin' => [
            'audit.export', 'audit.view',
            'catalogs.manage', 'catalogs.view',
            'people.create', 'people.delete', 'people.edit', 'people.view',
            'settings.manage', 'settings.view',
            'users.manage',
        ],
        'director' => [
            'catalogs.view',
            'people.view',
            'settings.view',
        ],
        'specialist' => [
            'catalogs.view',
            'people.view',
            'settings.view',
        ],
        'operator' => [
            'catalogs.view',
            'people.create', 'people.edit', 'people.view',
        ],
        'auditor' => [
            'audit.export', 'audit.view',
            'catalogs.view',
            'people.view',
            'settings.view',
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
