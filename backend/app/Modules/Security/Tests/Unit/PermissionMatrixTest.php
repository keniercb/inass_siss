<?php

declare(strict_types=1);

namespace App\Modules\Security\Tests\Unit;

use App\Modules\Security\Domain\Authorization\PermissionMatrix;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Role-permission matrix of the initial RBAC seed (RF-SEG-002, S3.4).
 *
 * The five institutional roles of section 2.2 (Requisitos funcionales)
 * are fixed here as executable acceptance criteria BEFORE the matrix
 * exists (TDD red). The dataset in test_the_role_permission_matrix_holds
 * is the "matriz rol-permiso como dataset de Pest" required by the
 * development plan: fase 6 will extend it to the complete matrix.
 */
final class PermissionMatrixTest extends TestCase
{
    public function test_declares_the_five_institutional_roles(): void
    {
        self::assertSame(
            ['admin', 'director', 'specialist', 'operator', 'auditor'],
            PermissionMatrix::roles(),
        );
    }

    public function test_exposes_the_initial_permission_catalog(): void
    {
        self::assertSame(
            [
                'audit.export',
                'audit.view',
                'catalogs.manage',
                'catalogs.view',
                'organizations.manage',
                'organizations.view',
                'people.create',
                'people.delete',
                'people.edit',
                'people.view',
                'settings.manage',
                'settings.view',
                'users.manage',
            ],
            PermissionMatrix::permissions(),
        );
    }

    /**
     * @dataProvider provideMatrix
     */
    public function test_the_role_permission_matrix_holds(string $role, string $permission, bool $granted): void
    {
        self::assertSame($granted, PermissionMatrix::roleHasPermission($role, $permission));
    }

    /**
     * The full matrix (S3.4): every role x permission pair with its
     * expected grant, so each cell is one executable acceptance
     * criterion. Derived from section 2.2 usage table:
     *
     *  admin      -> all modules (technical administrator)
     *  director   -> read-only consultation (EXP/CAL/REP come with their phases)
     *  specialist -> read-only consultation for case work
     *  operator   -> registers people (PER) and consults catalogs
     *  auditor    -> strictly read-only plus the audit trail
     *
     * @return list<array{string, string, bool}>
     */
    public static function provideMatrix(): array
    {
        $expected = [
            'admin' => [
                'audit.export' => true, 'audit.view' => true,
                'catalogs.manage' => true, 'catalogs.view' => true,
                'organizations.manage' => true, 'organizations.view' => true,
                'people.create' => true, 'people.delete' => true,
                'people.edit' => true, 'people.view' => true,
                'settings.manage' => true, 'settings.view' => true,
                'users.manage' => true,
            ],
            'director' => [
                'audit.export' => false, 'audit.view' => false,
                'catalogs.manage' => false, 'catalogs.view' => true,
                'organizations.manage' => false, 'organizations.view' => true,
                'people.create' => false, 'people.delete' => false,
                'people.edit' => false, 'people.view' => true,
                'settings.manage' => false, 'settings.view' => true,
                'users.manage' => false,
            ],
            'specialist' => [
                'audit.export' => false, 'audit.view' => false,
                'catalogs.manage' => false, 'catalogs.view' => true,
                'organizations.manage' => false, 'organizations.view' => true,
                'people.create' => false, 'people.delete' => false,
                'people.edit' => false, 'people.view' => true,
                'settings.manage' => false, 'settings.view' => true,
                'users.manage' => false,
            ],
            'operator' => [
                'audit.export' => false, 'audit.view' => false,
                'catalogs.manage' => false, 'catalogs.view' => true,
                'organizations.manage' => false, 'organizations.view' => true,
                'people.create' => true, 'people.delete' => false,
                'people.edit' => true, 'people.view' => true,
                'settings.manage' => false, 'settings.view' => false,
                'users.manage' => false,
            ],
            'auditor' => [
                'audit.export' => true, 'audit.view' => true,
                'catalogs.manage' => false, 'catalogs.view' => true,
                'organizations.manage' => false, 'organizations.view' => true,
                'people.create' => false, 'people.delete' => false,
                'people.edit' => false, 'people.view' => true,
                'settings.manage' => false, 'settings.view' => true,
                'users.manage' => false,
            ],
        ];

        $cases = [];
        foreach ($expected as $role => $grants) {
            foreach (array_keys($grants) as $permission) {
                $cases[] = [$role, $permission, $grants[$permission]];
            }
        }

        return $cases;
    }

    public function test_every_role_has_at_least_one_permission(): void
    {
        foreach (PermissionMatrix::roles() as $role) {
            self::assertNotEmpty(
                PermissionMatrix::permissionsFor($role),
                "Role [{$role}] must hold at least one permission.",
            );
        }
    }

    public function test_admin_holds_every_permission(): void
    {
        self::assertSame(PermissionMatrix::permissions(), PermissionMatrix::permissionsFor('admin'));
    }

    public function test_auditor_is_strictly_read_only(): void
    {
        $writing = [
            'catalogs.manage', 'settings.manage', 'users.manage',
            'organizations.manage',
            'people.create', 'people.edit', 'people.delete',
        ];

        foreach ($writing as $permission) {
            self::assertFalse(
                PermissionMatrix::roleHasPermission('auditor', $permission),
                "Auditor must not hold writing permission [{$permission}] (solo lectura, sección 2.2).",
            );
        }
    }

    public function test_catalog_and_settings_management_is_admin_exclusive(): void
    {
        foreach (['catalogs.manage', 'settings.manage', 'users.manage', 'organizations.manage'] as $permission) {
            foreach (PermissionMatrix::roles() as $role) {
                if ($role === 'admin') {
                    continue;
                }

                self::assertFalse(
                    PermissionMatrix::roleHasPermission($role, $permission),
                    "Permission [{$permission}] must be exclusive to admin, got role [{$role}].",
                );
            }
        }
    }

    public function test_operator_registers_people_but_cannot_delete_them(): void
    {
        self::assertTrue(PermissionMatrix::roleHasPermission('operator', 'people.create'));
        self::assertTrue(PermissionMatrix::roleHasPermission('operator', 'people.edit'));
        self::assertFalse(PermissionMatrix::roleHasPermission('operator', 'people.delete'));
    }

    public function test_unknown_permission_is_not_granted(): void
    {
        self::assertFalse(PermissionMatrix::roleHasPermission('admin', 'cases.approve'));
    }

    public function test_unknown_role_fails_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PermissionMatrix::permissionsFor('intern');
    }
}
