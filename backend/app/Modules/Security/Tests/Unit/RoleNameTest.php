<?php

declare(strict_types=1);

namespace App\Modules\Security\Tests\Unit;

use App\Modules\Security\Domain\Authorization\PermissionMatrix;
use App\Modules\Security\Domain\Authorization\RoleName;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * RoleName domain value (RF-SEG-002, ADR-26): the naming contract
 * for custom roles created through the management surface.
 *
 * A custom role name must be a machine-friendly lowercase slug that
 * cannot collide with the institutional matrix: the five roles of
 * section 2.2 are code-owned policy (PermissionMatrix), so the API
 * reserves their names forever. The rules are pure domain so any
 * caller (FormRequest, service, future CLI importer) shares ONE
 * definition.
 */
final class RoleNameTest extends TestCase
{
    public function test_accepts_a_lowercase_slug(): void
    {
        self::assertSame('supervisor_territorial', RoleName::fromString('supervisor_territorial')->value());
        self::assertSame('case_worker2', RoleName::fromString('case_worker2')->value());
        self::assertSame('ab', RoleName::fromString('ab')->value());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideInvalidNames(): array
    {
        return [
            'empty' => [''],
            'single letter' => ['a'],
            'leading digit' => ['2nd_operator'],
            'leading underscore' => ['_private'],
            'uppercase' => ['Supervisor'],
            'internal space' => ['case worker'],
            'internal dash' => ['case-worker'],
            'internal dot' => ['case.worker'],
            'too long' => ['a_very_long_role_name_that_exceeds_the_limit'],
            'spanish accent' => ['operación'],
        ];
    }

    /**
     * @dataProvider provideInvalidNames
     */
    public function test_rejects_names_outside_the_slug_contract(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        RoleName::fromString($name);
    }

    public function test_institutional_names_are_reserved(): void
    {
        foreach (PermissionMatrix::roles() as $institutional) {
            self::assertTrue(RoleName::isReserved($institutional), "Role [{$institutional}] must be reserved.");
        }
    }

    public function test_custom_names_are_not_reserved(): void
    {
        self::assertFalse(RoleName::isReserved('supervisor_territorial'));
        self::assertFalse(RoleName::isReserved('cajero'));
    }
}
