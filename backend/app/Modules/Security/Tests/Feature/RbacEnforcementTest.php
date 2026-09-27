<?php

declare(strict_types=1);

namespace App\Modules\Security\Tests\Feature;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\Municipality;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Province;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Race;
use App\Modules\Security\Domain\Authorization\PermissionMatrix;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use App\Modules\Settings\Infrastructure\Persistence\Models\GeneralSetting;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RBAC enforcement on the existing API surface (RF-SEG-002, S3.4).
 *
 * The PermissionMatrix must be live in the HTTP layer, not just
 * seeded data: reads answer only to *.view, writes only to *.manage,
 * a user without roles is rejected with 403 and an anonymous request
 * keeps answering 401. Roles are assigned through the real spatie
 * tables seeded by RolesAndPermissionsSeeder, so every assertion
 * exercises the actual package wiring (guard, caches, pivots).
 */
final class RbacEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $raceId;

    private int $provinceId;

    /**
     * Read endpoints with the permission they demand and the status
     * they answer when the permission is held: the expected status per
     * role is DERIVED from the PermissionMatrix, so this suite keeps
     * proving the live matrix, not a hardcoded copy of it.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: int}>
     */
    public static function protectedReadEndpointsProvider(): array
    {
        return [
            'catalogs index' => ['GET', '/api/v1/catalogs/agency-types', 'catalogs.view', 200],
            'catalogs show' => ['GET', '/api/v1/catalogs/races/{race}', 'catalogs.view', 200],
            'municipalities index' => ['GET', '/api/v1/municipalities', 'catalogs.view', 200],
            'agencies index' => ['GET', '/api/v1/agencies', 'catalogs.view', 200],
            'settings current' => ['GET', '/api/v1/general-settings/current', 'settings.view', 200],
            'settings index' => ['GET', '/api/v1/general-settings', 'settings.view', 200],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: int}>
     */
    public static function protectedWriteEndpointsProvider(): array
    {
        return [
            'catalogs store' => [
                'POST', '/api/v1/catalogs/agency-types',
                ['code' => 'X99', 'name' => 'Caja de ahorro'], 201,
            ],
            'municipalities store' => [
                'POST', '/api/v1/municipalities',
                ['code' => '999', 'name' => 'Sanación', 'province_id' => '{province}'], 201,
            ],
            'settings store' => [
                'POST', '/api/v1/general-settings',
                [
                    'min_work_years' => 25,
                    'min_age_men' => 60,
                    'min_age_women' => 55,
                    'base_calc_percent' => 50,
                    'max_calc_percent' => 90,
                    'annual_increase_percent' => 1,
                    'effective_from' => '2030-01-01',
                ], 201,
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic fixtures so read endpoints answer 200 (not 404
        // on empty) and write payloads satisfy validation: the RBAC
        // suite must only ever fail on permission grounds. Ids are
        // captured because AUTO_INCREMENT does not roll back between
        // tests, so "id 1" is never a safe assumption.
        $this->raceId = Race::query()->create(['name' => 'Otra'])->id;

        $province = Province::query()->create(['code' => '01', 'name' => 'Pinar del Río']);
        $this->provinceId = $province->id;

        Municipality::query()->create([
            'province_id' => $province->id,
            'code' => '101',
            'name' => 'Pinar del Río',
        ]);

        GeneralSetting::query()->create([
            'min_work_years' => 25,
            'min_age_men' => 60,
            'min_age_women' => 55,
            'base_calc_percent' => 50,
            'max_calc_percent' => 90,
            'annual_increase_percent' => 1,
            'effective_from' => '2020-01-01',
        ]);
    }

    /**
     * @dataProvider protectedReadEndpointsProvider
     */
    public function test_every_role_reads_exactly_what_the_matrix_grants(string $method, string $uriTemplate, string $permission, int $successStatus): void
    {
        $uri = str_replace('{race}', (string) $this->raceId, $uriTemplate);

        foreach (PermissionMatrix::roles() as $role) {
            $this->refreshAuthenticatedUser($role);

            $response = $this->call($method, $uri);

            $expected = PermissionMatrix::roleHasPermission($role, $permission) ? $successStatus : 403;

            self::assertSame(
                $expected,
                $response->status(),
                "Role [{$role}] hitting [{$method} {$uri}] expected [{$expected}] for permission [{$permission}].",
            );
        }
    }

    /**
     * @dataProvider protectedWriteEndpointsProvider
     *
     * @param  array<string, mixed>  $payload
     */
    public function test_only_admin_writes_catalogs_municipalities_and_settings(string $method, string $uri, array $payload, int $expectedStatus): void
    {
        $uri = str_replace('{province}', (string) $this->provinceId, $uri);
        $payload = str_replace('{province}', (string) $this->provinceId, $payload);

        foreach (PermissionMatrix::roles() as $role) {
            $this->refreshAuthenticatedUser($role);

            $response = $this->call($method, $uri, $role === 'admin' ? $payload : []);

            $expected = $role === 'admin' ? $expectedStatus : 403;

            self::assertSame(
                $expected,
                $response->status(),
                "Role [{$role}] hitting [{$method} {$uri}] expected [{$expected}].",
            );
        }
    }

    public function test_user_without_roles_is_forbidden_on_every_protected_endpoint(): void
    {
        $this->refreshAuthenticatedUser(null);

        $this->getJson('/api/v1/catalogs/agency-types')->assertForbidden();
        $this->postJson('/api/v1/catalogs/agency-types', [])->assertForbidden();
        $this->getJson('/api/v1/general-settings/current')->assertForbidden();
        $this->postJson('/api/v1/general-settings', [])->assertForbidden();
    }

    public function test_anonymous_requests_keep_answering_401(): void
    {
        $this->getJson('/api/v1/catalogs/agency-types')->assertUnauthorized();
        $this->postJson('/api/v1/general-settings', [])->assertUnauthorized();
    }

    public function test_operator_reads_catalogs_but_cannot_manage_them(): void
    {
        $this->refreshAuthenticatedUser('operator');

        self::assertTrue($this->user->hasPermissionTo('people.create'));
        self::assertFalse($this->user->hasPermissionTo('catalogs.manage'));

        $this->getJson('/api/v1/catalogs/agency-types')->assertOk();
        $this->postJson('/api/v1/catalogs/agency-types', ['code' => 'X98', 'name' => 'Sucursal móvil'])
            ->assertForbidden();
    }

    public function test_auditor_reads_settings_but_cannot_write_them(): void
    {
        $this->refreshAuthenticatedUser('auditor');

        $this->getJson('/api/v1/general-settings/current')->assertOk();
        $this->postJson('/api/v1/general-settings', [])->assertForbidden();
    }

    public function test_seeder_is_idempotent_and_converges_to_the_matrix(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        foreach (PermissionMatrix::roles() as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            foreach (PermissionMatrix::permissions() as $permission) {
                self::assertSame(
                    PermissionMatrix::roleHasPermission($role, $permission),
                    $user->hasPermissionTo($permission),
                    "Seed mismatch for role [{$role}] and permission [{$permission}].",
                );
            }
        }

        $this->assertDatabaseCount('roles', count(PermissionMatrix::roles()));
        $this->assertDatabaseCount('permissions', count(PermissionMatrix::permissions()));
    }

    public function test_me_endpoint_returns_roles_and_permissions(): void
    {
        $this->refreshAuthenticatedUser('operator');

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $this->user->email)
            ->assertJsonPath('data.roles.0', 'operator')
            ->assertJsonPath('data.permissions', PermissionMatrix::permissionsFor('operator'));
    }

    private function refreshAuthenticatedUser(?string $role): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->create();

        if ($role !== null) {
            $this->user->assignRole($role);
        }

        $this->actingAs($this->user);
    }
}
