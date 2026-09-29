<?php

declare(strict_types=1);

namespace App\Modules\Security\Tests\Feature;

use App\Modules\Security\Domain\Authorization\PermissionMatrix;
use App\Modules\Security\Infrastructure\Persistence\Models\Role;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Permission catalog surface (RF-SEG-002, ADR-27): the read-only
 * directory the role editor consumes — one entry per catalog
 * permission with its module.action decomposition, the institutional
 * holders (from the matrix, code-owned), the custom roles holding it
 * (live pivots) and the count of accounts that can act on it
 * (deactivated included: their pivots keep reserving the grants).
 */
final class PermissionCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsRole('admin');
    }

    // ------------------------------------------------------------------
    // Catalog listing (roles.view)
    // ------------------------------------------------------------------

    public function test_index_lists_the_whole_catalog_with_the_module_action_split(): void
    {
        $response = $this->getJson('/api/v1/permissions');

        $response->assertOk()
            ->assertJsonCount(count(PermissionMatrix::permissions()), 'data');

        /** @var list<array<string, mixed>> $data */
        $data = (array) $response->json('data');

        $names = array_map(
            fn (array $entry): string => (string) ($entry['name'] ?? ''),
            $data,
        );

        self::assertSame(PermissionMatrix::permissions(), $names);

        $response->assertJsonFragment([
            'name' => 'people.view',
            'module' => 'people',
            'action' => 'view',
        ])->assertJsonFragment([
            'name' => 'legalbases.manage',
            'module' => 'legalbases',
            'action' => 'manage',
        ]);
    }

    public function test_index_reflects_the_institutional_holders_of_the_matrix(): void
    {
        $this->getJson('/api/v1/permissions')
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'cases.view',
                'institutional_roles' => ['admin', 'director', 'specialist', 'operator', 'auditor'],
            ])
            ->assertJsonFragment([
                'name' => 'roles.manage',
                'institutional_roles' => ['admin'],
            ]);
    }

    public function test_index_reflects_custom_roles_created_by_the_management_surface(): void
    {
        $this->postJson('/api/v1/roles', [
            'name' => 'supervisor_territorial',
            'description' => 'Supervisa la captura de una provincia',
            'permissions' => ['cases.view', 'people.view'],
        ])->assertCreated();

        $this->getJson('/api/v1/permissions')
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'cases.view',
                'custom_roles' => ['supervisor_territorial'],
            ])
            ->assertJsonFragment([
                'name' => 'people.view',
                'custom_roles' => ['supervisor_territorial'],
            ]);
    }

    public function test_index_counts_the_effective_accounts_per_permission(): void
    {
        $role = Role::query()->create([
            'name' => 'visor',
            'guard_name' => 'web',
            'description' => 'Solo consulta',
            'is_system' => false,
        ]);
        $role->syncPermissions(['people.view']);

        $holder = User::factory()->create();
        $holder->assignRole('visor');

        // people.view: the acting admin (admin holds everything) plus
        // the visor holder — distinct accounts across the roles that
        // grant the permission.
        self::assertSame(2, $this->permissionEntry('people.view')['users_count']);

        // A permission nobody else holds: only the acting admin.
        self::assertSame(1, $this->permissionEntry('settings.manage')['users_count']);
    }

    public function test_users_count_spans_deactivated_accounts(): void
    {
        $role = Role::query()->create([
            'name' => 'visor',
            'guard_name' => 'web',
            'description' => 'Solo consulta',
            'is_system' => false,
        ]);
        $role->syncPermissions(['catalogs.view']);

        $holder = User::factory()->create();
        $holder->assignRole('visor');

        $before = $this->permissionEntry('catalogs.view');

        // Deactivation keeps the pivot (ADR-24: the account reserves
        // its roles), so the effective count must not shrink.
        $holder->delete();

        $after = $this->permissionEntry('catalogs.view');

        self::assertSame($before['users_count'], $after['users_count']);
        self::assertGreaterThan(1, $after['users_count']);
    }

    // ------------------------------------------------------------------
    // Detail (roles.view)
    // ------------------------------------------------------------------

    public function test_show_returns_one_permission(): void
    {
        $this->getJson('/api/v1/permissions/people.view')
            ->assertOk()
            ->assertJsonPath('data.name', 'people.view')
            ->assertJsonPath('data.module', 'people')
            ->assertJsonPath('data.action', 'view')
            ->assertJsonPath('data.institutional_roles', ['admin', 'director', 'specialist', 'operator', 'auditor'])
            ->assertJsonPath('data.custom_roles', [])
            ->assertJsonPath('data.users_count', 1);
    }

    public function test_show_answers_404_for_unknown_permissions(): void
    {
        // Well-formed name that is not part of the catalog.
        $this->getJson('/api/v1/permissions/cases.approve')->assertNotFound();
    }

    public function test_show_answers_404_for_malformed_names(): void
    {
        // The route pattern itself rejects anything that is not a
        // lowercase module.action pair.
        $this->getJson('/api/v1/permissions/people')->assertNotFound();
        $this->getJson('/api/v1/permissions/People.View')->assertNotFound();
        $this->getJson('/api/v1/permissions/people.view.extra')->assertNotFound();
    }

    /**
     * Fetches one catalog entry by name off the live index.
     *
     * @return array<string, mixed>
     */
    private function permissionEntry(string $name): array
    {
        /** @var list<array<string, mixed>> $data */
        $data = (array) $this->getJson('/api/v1/permissions')->json('data');

        foreach ($data as $entry) {
            if (($entry['name'] ?? null) === $name) {
                return $entry;
            }
        }

        self::fail("Permission [{$name}] missing from the catalog.");
    }
}
