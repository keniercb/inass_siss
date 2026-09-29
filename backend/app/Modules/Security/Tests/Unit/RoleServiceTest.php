<?php

declare(strict_types=1);

namespace App\Modules\Security\Tests\Unit;

use App\Modules\Security\Application\Exceptions\RoleInUseException;
use App\Modules\Security\Application\Services\RoleService;
use App\Modules\Security\Infrastructure\Persistence\Models\Role;
use App\Modules\Security\Tests\Fakes\InMemoryRoleRepository;
use App\Modules\Security\Tests\Support\BootsMinimalValidator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the role management use cases (RF-SEG-002, ADR-26):
 * custom role creation with the RoleName contract and the permission
 * catalog, edition, institutional immutability, deletion guards and
 * the in-use conflict.
 *
 * Runs without a database through the in-memory fake; the minimal
 * RBAC container seam lets the spatie Role model instantiate (its
 * constructor resolves the guard from the auth configuration).
 */
final class RoleServiceTest extends TestCase
{
    use BootsMinimalValidator;

    private InMemoryRoleRepository $roles;

    private RoleService $service;

    private Role $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindMinimalValidatorFacade();

        $this->operator = new Role(['name' => 'operator', 'guard_name' => 'web']);
        $this->operator->forceFill(['is_system' => true, 'description' => 'Operador de captura.']);
        $this->operator->id = 2;

        $this->roles = new InMemoryRoleRepository;
        $this->roles->seed($this->operator, ['people.create', 'people.edit', 'people.view']);

        $this->service = new RoleService($this->roles);
    }

    protected function tearDown(): void
    {
        $this->forgetMinimalContainer();

        parent::tearDown();
    }

    public function test_lists_every_role(): void
    {
        $all = $this->service->list();

        self::assertInstanceOf(Collection::class, $all);
        self::assertCount(1, $all);

        $first = $all->first();
        self::assertInstanceOf(Role::class, $first);
        self::assertSame('operator', $first->name);
    }

    public function test_finds_a_role_by_id(): void
    {
        self::assertSame($this->operator, $this->service->find(2));
        self::assertNull($this->service->find(99));
    }

    public function test_creates_a_custom_role_with_a_permission_subset(): void
    {
        $created = $this->service->create(
            'supervisor_territorial',
            'Supervisa la captura territorial',
            ['people.view', 'cases.view'],
        );

        self::assertSame('supervisor_territorial', $created->name);
        self::assertFalse((bool) $created->is_system);
        self::assertSame($created, $this->roles->lastCreated);
        self::assertSame(['people.view', 'cases.view'], $this->roles->permissions[(int) $created->id]);
    }

    public function test_create_rejects_a_name_outside_the_slug_contract(): void
    {
        $this->assertValidationFails(
            fn () => $this->service->create('Supervisor Territorial', null, ['people.view']),
            'name',
        );
    }

    public function test_create_rejects_institutional_reserved_names(): void
    {
        foreach (['admin', 'director', 'specialist', 'operator', 'auditor'] as $reserved) {
            $this->assertValidationFails(
                fn () => $this->service->create($reserved, null, ['people.view']),
                'name',
                "Reserved name [{$reserved}] must answer 422.",
            );
        }
    }

    public function test_create_rejects_permissions_outside_the_catalog(): void
    {
        $this->assertValidationFails(
            fn () => $this->service->create('supervisor', null, ['people.view', 'cases.approve']),
            'permissions',
        );
    }

    public function test_create_requires_at_least_one_permission(): void
    {
        $this->assertValidationFails(
            fn () => $this->service->create('supervisor', null, []),
            'permissions',
        );
    }

    public function test_create_rejects_a_name_already_taken(): void
    {
        $this->roles->seed(
            self::customRole('supervisor', 30),
            ['people.view'],
        );

        $this->assertValidationFails(
            fn () => $this->service->create('supervisor', 'Otro', ['people.view']),
            'name',
        );
    }

    public function test_update_renames_and_re_grants_a_custom_role(): void
    {
        $role = $this->roles->seed(
            self::customRole('supervisor', 30),
            ['people.view'],
        );

        $updated = $this->service->update(30, 'supervisor_territorial', 'Nueva descripción', ['people.view', 'cases.view']);

        self::assertNotNull($updated);
        self::assertSame('supervisor_territorial', $updated->name);
        self::assertSame('Nueva descripción', $updated->description);
        self::assertSame(['people.view', 'cases.view'], $this->roles->permissions[30]);
    }

    public function test_update_returns_null_for_an_unknown_role(): void
    {
        self::assertNull($this->service->update(99, 'nada', null, null));
    }

    public function test_update_leaves_the_role_untouched_when_nothing_arrives(): void
    {
        $role = $this->roles->seed(
            self::customRole('supervisor', 30),
            ['people.view'],
        );

        $updated = $this->service->update(30, null, null, null);

        self::assertSame($role, $updated);
        self::assertSame('supervisor', $updated->name);
        self::assertSame(['people.view'], $this->roles->permissions[30]);
    }

    public function test_update_rejects_institutional_roles(): void
    {
        $this->assertValidationFails(
            fn () => $this->service->update(2, 'otro_nombre', null, null),
            'name',
        );

        $this->assertValidationFails(
            fn () => $this->service->update(2, null, null, ['people.view']),
            'name',
        );
    }

    public function test_update_rejects_a_name_taken_by_another_role(): void
    {
        $this->roles->seed(self::customRole('supervisor', 30), ['people.view']);
        $this->roles->seed(self::customRole('visor', 31), ['people.view']);

        $this->assertValidationFails(
            fn () => $this->service->update(31, 'supervisor', null, null),
            'name',
        );
    }

    public function test_update_rejects_permissions_outside_the_catalog(): void
    {
        $this->roles->seed(self::customRole('supervisor', 30), ['people.view']);

        $this->assertValidationFails(
            fn () => $this->service->update(30, null, null, ['users.manage', 'no.existe']),
            'permissions',
        );
    }

    public function test_update_allows_keeping_the_current_name(): void
    {
        $role = $this->roles->seed(self::customRole('supervisor', 30), ['people.view']);

        $updated = $this->service->update(30, 'supervisor', 'Descripción nueva', null);

        self::assertNotNull($updated);
        self::assertSame('Descripción nueva', $updated->description);
    }

    public function test_delete_removes_an_unused_custom_role(): void
    {
        $role = $this->roles->seed(self::customRole('supervisor', 30), ['people.view']);

        self::assertTrue($this->service->delete(30));
        self::assertSame(1, $this->roles->deletions);
        self::assertNull($this->roles->find(30));
    }

    public function test_delete_returns_false_for_an_unknown_role(): void
    {
        self::assertFalse($this->service->delete(99));
        self::assertSame(0, $this->roles->deletions);
    }

    public function test_delete_rejects_institutional_roles(): void
    {
        $this->assertValidationFails(
            fn () => $this->service->delete(2),
            'name',
        );

        self::assertSame(0, $this->roles->deletions);
    }

    public function test_delete_rejects_roles_still_in_use(): void
    {
        $this->roles->seed(self::customRole('supervisor', 30), ['people.view'], 4);

        try {
            $this->service->delete(30);
            self::fail('RoleInUseException was expected.');
        } catch (RoleInUseException $exception) {
            self::assertSame(30, $exception->roleId);
            self::assertSame(4, $exception->usersCount);
        }

        self::assertSame(0, $this->roles->deletions);
    }

    private static function customRole(string $name, int $id): Role
    {
        $role = new Role(['name' => $name, 'guard_name' => 'web']);
        $role->forceFill(['is_system' => false, 'description' => null]);
        $role->id = $id;

        return $role;
    }

    /**
     * @param  callable(): mixed  $attempt
     */
    private function assertValidationFails(callable $attempt, string $key, string $context = ''): void
    {
        try {
            $attempt();
            self::fail('ValidationException was expected. '.$context);
        } catch (ValidationException $exception) {
            self::assertArrayHasKey($key, $exception->errors(), $context);
        }
    }
}
