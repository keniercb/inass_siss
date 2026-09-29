<?php

declare(strict_types=1);

namespace App\Modules\Security\Tests\Feature;

use App\Modules\Security\Domain\Authorization\PermissionMatrix;
use App\Modules\Security\Infrastructure\Persistence\Models\Role;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Role management surface (RF-SEG-002, ADR-26): the directory mixes
 * the five immutable institutional roles with the custom roles the
 * Administrator creates as permission subsets. Every write lands in
 * the append-only bitácora with the previous permission set — role
 * pivots are invisible to Eloquent events, so the repository records
 * them explicitly (AuditRecorder, ADR-19/ADR-24). A role still held
 * by accounts cannot be deleted (409 with the count); institutional
 * roles answer 422 for any write; a custom role is assignable to
 * user accounts and grants its permissions effectively.
 */
final class RoleManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->actingAsRole('admin');
    }

    // ------------------------------------------------------------------
    // Directory (roles.view)
    // ------------------------------------------------------------------

    public function test_index_lists_institutional_and_custom_roles(): void
    {
        $this->postJson('/api/v1/roles', [
            'name' => 'supervisor_territorial',
            'description' => 'Supervisa la captura de una provincia',
            'permissions' => ['cases.view', 'people.view'],
        ])->assertCreated();

        $response = $this->getJson('/api/v1/roles');

        $response->assertOk()
            ->assertJsonCount(6, 'data');

        // The institutional block comes from the seeded matrix: every
        // role carries its grants, the system flag and the account
        // count (the acting admin holds admin).
        $response->assertJsonFragment([
            'name' => 'operator',
            'is_system' => true,
            'permissions' => PermissionMatrix::permissionsFor('operator'),
        ])->assertJsonFragment([
            'name' => 'admin',
            'is_system' => true,
            'users_count' => 1,
        ])->assertJsonFragment([
            'name' => 'supervisor_territorial',
            'description' => 'Supervisa la captura de una provincia',
            'is_system' => false,
            'users_count' => 0,
        ]);

        // The permission catalog travels as meta so the client can
        // render the grant picker without another endpoint.
        $response->assertJsonPath('meta.permissions', PermissionMatrix::permissions());
    }

    public function test_show_returns_one_role(): void
    {
        $operator = Role::query()->where('name', 'operator')->firstOrFail();

        $this->getJson('/api/v1/roles/'.$operator->id)
            ->assertOk()
            ->assertJsonPath('data.name', 'operator')
            ->assertJsonPath('data.is_system', true)
            ->assertJsonPath('data.permissions', PermissionMatrix::permissionsFor('operator'));

        $this->getJson('/api/v1/roles/99999')->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Creation (roles.manage)
    // ------------------------------------------------------------------

    public function test_store_creates_a_custom_role(): void
    {
        $response = $this->postJson('/api/v1/roles', [
            'name' => 'supervisor_territorial',
            'description' => 'Supervisa la captura de una provincia',
            'permissions' => ['cases.view', 'people.view'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'supervisor_territorial')
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.users_count', 0)
            ->assertJsonPath('data.permissions', ['cases.view', 'people.view']);

        $stored = Role::query()->where('name', 'supervisor_territorial')->firstOrFail();
        self::assertSame((int) $stored->id, $response->json('data.id'));
        self::assertSame(['cases.view', 'people.view'], $stored->permissions->pluck('name')->sort()->values()->all());
    }

    public function test_store_creates_the_role_without_a_description(): void
    {
        $this->postJson('/api/v1/roles', [
            'name' => 'visor_limitado',
            'permissions' => ['cases.view'],
        ])->assertCreated()
            ->assertJsonPath('data.description', null);
    }

    public function test_store_lands_in_the_bitacora_with_the_actor_and_the_grants(): void
    {
        $this->postJson('/api/v1/roles', [
            'name' => 'supervisor_territorial',
            'description' => 'Supervisa la captura de una provincia',
            'permissions' => ['cases.view', 'people.view'],
        ])->assertCreated();

        $role = Role::query()->where('name', 'supervisor_territorial')->firstOrFail();

        $entries = Activity::query()
            ->where('subject_type', $role->getMorphClass())
            ->where('subject_id', $role->id)
            ->get();

        // Row snapshot (created event) + explicit permission grant
        // entry for the pivot write the observer cannot see.
        self::assertCount(2, $entries);
        self::assertTrue($entries->contains(fn (Activity $entry) => $entry->event === 'created'));
        self::assertTrue(
            $entries->contains(fn (Activity $entry) => $entry->event === 'updated'
                && ($entry->properties['attributes']['permissions'] ?? null) === ['cases.view', 'people.view']),
        );
        self::assertTrue($entries->every(fn (Activity $entry) => (int) $entry->causer_id === (int) $this->admin->id));
    }

    public function test_store_works_without_a_description_for_the_bitacora_too(): void
    {
        $response = $this->postJson('/api/v1/roles', [
            'name' => 'visor_limitado',
            'permissions' => ['cases.view'],
        ])->assertCreated();

        // Scoped to the created role: the seeding of the institutional
        // matrix also writes honest trail entries, so a global count
        // would measure the seeder, not this endpoint.
        self::assertSame(
            2,
            Activity::query()
                ->where('subject_type', (new Role)->getMorphClass())
                ->where('subject_id', (int) $response->json('data.id'))
                ->count(),
        );
    }

    public function test_store_rejects_institutional_reserved_names(): void
    {
        $this->postJson('/api/v1/roles', [
            'name' => 'auditor',
            'permissions' => ['cases.view'],
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideInvalidNames(): array
    {
        return [
            'uppercase' => ['Supervisor'],
            'con espacios' => ['case worker'],
            'guión' => ['case-worker'],
            'largo' => ['a_very_long_role_name_that_exceeds_the_limit'],
            'guion bajo inicial' => ['_privado'],
        ];
    }

    /**
     * @dataProvider provideInvalidNames
     */
    public function test_store_rejects_names_outside_the_slug_contract(string $name): void
    {
        $this->postJson('/api/v1/roles', [
            'name' => $name,
            'permissions' => ['cases.view'],
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_store_rejects_permissions_outside_the_catalog(): void
    {
        $this->postJson('/api/v1/roles', [
            'name' => 'supervisor',
            'permissions' => ['cases.view', 'cases.approve'],
        ])->assertUnprocessable()->assertJsonValidationErrors('permissions.1');
    }

    public function test_store_requires_at_least_one_permission(): void
    {
        $this->postJson('/api/v1/roles', [
            'name' => 'supervisor',
            'permissions' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('permissions');
    }

    public function test_store_rejects_a_name_already_taken(): void
    {
        $this->postJson('/api/v1/roles', [
            'name' => 'supervisor',
            'permissions' => ['cases.view'],
        ])->assertCreated();

        $this->postJson('/api/v1/roles', [
            'name' => 'supervisor',
            'permissions' => ['people.view'],
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    // ------------------------------------------------------------------
    // Edition (roles.manage)
    // ------------------------------------------------------------------

    public function test_update_renames_and_re_grants_a_custom_role(): void
    {
        $roleId = $this->createCustomRole('supervisor', ['people.view']);

        $this->patchJson("/api/v1/roles/{$roleId}", [
            'name' => 'supervisor_territorial',
            'description' => 'Ahora con alcance provincial',
            'permissions' => ['cases.view', 'people.view'],
        ])->assertOk()
            ->assertJsonPath('data.name', 'supervisor_territorial')
            ->assertJsonPath('data.description', 'Ahora con alcance provincial')
            ->assertJsonPath('data.permissions', ['cases.view', 'people.view']);

        $stored = Role::query()->findOrFail($roleId);
        self::assertSame(['cases.view', 'people.view'], $stored->permissions->pluck('name')->sort()->values()->all());
    }

    public function test_update_records_the_previous_grants_in_the_bitacora(): void
    {
        $roleId = $this->createCustomRole('supervisor', ['people.view']);

        $this->patchJson("/api/v1/roles/{$roleId}", [
            'permissions' => ['cases.view', 'people.view'],
        ])->assertOk();

        $grantEntries = Activity::query()
            ->where('subject_type', (new Role)->getMorphClass())
            ->where('event', 'updated')
            ->get();

        self::assertTrue(
            $grantEntries->contains(
                fn (Activity $entry) => ($entry->properties['old']['permissions'] ?? null) === ['people.view']
                    && ($entry->properties['attributes']['permissions'] ?? null) === ['cases.view', 'people.view'],
            ),
        );
    }

    public function test_update_is_silent_in_the_bitacora_when_nothing_changes(): void
    {
        $roleId = $this->createCustomRole('supervisor', ['people.view']);

        $before = Activity::query()->count();

        $this->patchJson("/api/v1/roles/{$roleId}", [
            'name' => 'supervisor',
            'permissions' => ['people.view'],
        ])->assertOk();

        self::assertSame($before, Activity::query()->count());
    }

    /**
     * Institutional roles are immutable through the API: their grants
     * live in the PermissionMatrix (single source of truth), so any
     * write answers 422 without touching the database.
     */
    public function test_update_rejects_institutional_roles(): void
    {
        $operator = Role::query()->where('name', 'operator')->firstOrFail();

        $this->patchJson('/api/v1/roles/'.$operator->id, [
            'description' => 'Cambio arbitrario',
        ])->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->patchJson('/api/v1/roles/'.$operator->id, [
            'permissions' => ['people.view', 'people.delete'],
        ])->assertUnprocessable()->assertJsonValidationErrors('name');

        self::assertSame(
            PermissionMatrix::permissionsFor('operator'),
            $operator->refresh()->permissions->pluck('name')->sort()->values()->all(),
        );
    }

    public function test_update_rejects_a_name_taken_by_another_role(): void
    {
        $first = $this->createCustomRole('supervisor', ['people.view']);
        $second = $this->createCustomRole('visor', ['cases.view']);

        $this->patchJson("/api/v1/roles/{$second}", [
            'name' => 'supervisor',
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_update_rejects_permissions_outside_the_catalog(): void
    {
        $roleId = $this->createCustomRole('supervisor', ['people.view']);

        $this->patchJson("/api/v1/roles/{$roleId}", [
            'permissions' => ['users.manage', 'no.existe'],
        ])->assertUnprocessable()->assertJsonValidationErrors('permissions.1');
    }

    public function test_update_answers_404_for_an_unknown_role(): void
    {
        $this->patchJson('/api/v1/roles/99999', [
            'description' => 'Nada',
        ])->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Deletion (roles.manage)
    // ------------------------------------------------------------------

    public function test_destroy_deletes_an_unused_custom_role(): void
    {
        $roleId = $this->createCustomRole('supervisor', ['people.view']);

        $this->deleteJson("/api/v1/roles/{$roleId}")->assertNoContent();

        self::assertNull(Role::query()->find($roleId));
    }

    public function test_destroy_leaves_the_permission_set_in_the_bitacora(): void
    {
        $roleId = $this->createCustomRole('supervisor', ['people.view', 'cases.view']);

        $this->deleteJson("/api/v1/roles/{$roleId}")->assertNoContent();

        $deleted = Activity::query()
            ->where('subject_type', (new Role)->getMorphClass())
            ->where('event', 'deleted')
            ->get();

        self::assertTrue(
            $deleted->contains(
                fn (Activity $entry) => ($entry->properties['old']['permissions'] ?? null) === ['cases.view', 'people.view']
                    && ($entry->properties['old']['name'] ?? null) === 'supervisor',
            ),
        );
    }

    public function test_destroy_rejects_institutional_roles(): void
    {
        $director = Role::query()->where('name', 'director')->firstOrFail();

        $this->deleteJson('/api/v1/roles/'.$director->id)
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        self::assertNotNull(Role::query()->find($director->id));
    }

    public function test_destroy_rejects_roles_still_in_use(): void
    {
        $roleId = $this->createCustomRole('supervisor', ['people.view']);

        $holder = User::factory()->create();
        $holder->assignRole('supervisor');

        $this->deleteJson("/api/v1/roles/{$roleId}")
            ->assertConflict()
            ->assertJsonPath('role_id', $roleId)
            ->assertJsonPath('users_count', 1)
            ->assertJsonPath('message', 'The role is still assigned to accounts. Unassign it before deleting it.');

        self::assertNotNull(Role::query()->find($roleId));
    }

    public function test_destroy_answers_404_for_an_unknown_role(): void
    {
        $this->deleteJson('/api/v1/roles/99999')->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Custom roles join the account surface (RF-SEG-002)
    // ------------------------------------------------------------------

    public function test_a_custom_role_is_assignable_and_grants_permissions_effectively(): void
    {
        $this->postJson('/api/v1/roles', [
            'name' => 'supervisor_territorial',
            'description' => 'Supervisa la captura de una provincia',
            'permissions' => ['cases.view', 'people.view'],
        ])->assertCreated();

        $this->postJson('/api/v1/users', [
            'name' => 'Víctor Supervisor',
            'email' => 'victor@sgp.local',
            'password' => 'Segura2026',
            'roles' => ['supervisor_territorial'],
        ])->assertCreated()
            ->assertJsonPath('data.roles', ['supervisor_territorial'])
            ->assertJsonPath('data.permissions', ['cases.view', 'people.view']);

        // The granted permission unlocks the corresponding surface for
        // the account; everything else stays forbidden.
        $victor = User::query()->where('email', 'victor@sgp.local')->firstOrFail();
        $this->actingAs($victor);

        $this->getJson('/api/v1/people')->assertOk();
        $this->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_user_assignment_still_rejects_unknown_roles(): void
    {
        $this->postJson('/api/v1/users', [
            'name' => 'Inventada',
            'email' => 'inventada@sgp.local',
            'password' => 'Segura2026',
            'roles' => ['emperador'],
        ])->assertUnprocessable()->assertJsonValidationErrors('roles.0');
    }

    /**
     * Creates a custom role through the API (the surface under test)
     * and returns its id.
     *
     * @param  list<string>  $permissions
     */
    private function createCustomRole(string $name, array $permissions): int
    {
        $response = $this->postJson('/api/v1/roles', [
            'name' => $name,
            'permissions' => $permissions,
        ])->assertCreated();

        return (int) $response->json('data.id');
    }
}
