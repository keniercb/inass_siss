<?php

declare(strict_types=1);

namespace App\Modules\Security\Tests\Feature;

use App\Modules\Security\Domain\Authorization\PermissionMatrix;
use App\Modules\Security\Infrastructure\Persistence\Models\Role;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * RBAC enforcement on the role management surface (RF-SEG-002,
 * ADR-26): reads answer to roles.view (Administrador y Auditor), the
 * writes answer to roles.manage (Administrador). The expected status
 * per role is DERIVED from the PermissionMatrix, so this suite keeps
 * proving the live matrix instead of a hardcoded copy of it.
 */
final class RbacRolesApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $customRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic fixture: a custom role the read/write surface
        // can point at. Ids are captured because AUTO_INCREMENT does
        // not roll back between tests.
        $this->seed(RolesAndPermissionsSeeder::class);

        $role = Role::query()->create([
            'name' => 'supervisor',
            'guard_name' => 'web',
            'description' => 'Supervisa la captura',
            'is_system' => false,
        ]);
        $role->syncPermissions(['people.view']);
        $this->customRoleId = (int) $role->id;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function roleEndpointsProvider(): array
    {
        return [
            'roles index' => ['GET', '/api/v1/roles', 'roles.view'],
            'roles show' => ['GET', '/api/v1/roles/{role}', 'roles.view'],
            'roles store' => ['POST', '/api/v1/roles', 'roles.manage'],
            'roles update' => ['PATCH', '/api/v1/roles/{role}', 'roles.manage'],
            'roles destroy' => ['DELETE', '/api/v1/roles/{role}', 'roles.manage'],
        ];
    }

    /**
     * @dataProvider roleEndpointsProvider
     */
    public function test_every_role_hits_exactly_what_the_matrix_grants(string $method, string $uriTemplate, string $permission): void
    {
        $uri = str_replace('{role}', (string) $this->customRoleId, $uriTemplate);

        foreach (PermissionMatrix::roles() as $role) {
            $this->refreshAuthenticatedUser($role);

            $payload = $method === 'POST'
                ? ['name' => 'visor_'.$role, 'permissions' => ['people.view']]
                : [];

            $response = $this->call($method, $uri, $payload);

            $expected = PermissionMatrix::roleHasPermission($role, $permission)
                ? self::successStatusFor($method)
                : 403;

            self::assertSame(
                $expected,
                $response->status(),
                "Role [{$role}] hitting [{$method} {$uri}] expected [{$expected}] for permission [{$permission}].",
            );
        }
    }

    public function test_anonymous_requests_keep_answering_401(): void
    {
        $this->getJson('/api/v1/roles')->assertUnauthorized();
        $this->postJson('/api/v1/roles', [])->assertUnauthorized();
    }

    public function test_a_user_without_roles_is_forbidden(): void
    {
        $this->refreshAuthenticatedUser(null);

        $this->getJson('/api/v1/roles')->assertForbidden();
        $this->deleteJson('/api/v1/roles/'.$this->customRoleId)->assertForbidden();
    }

    private function refreshAuthenticatedUser(?string $role): void
    {
        $this->user = User::factory()->create();

        if ($role !== null) {
            $this->user->assignRole($role);
        }

        $this->actingAs($this->user);
    }

    /**
     * The success status each method answers for a valid payload when
     * the permission is held: the DELETE of the fixture deletes it,
     * but the middleware runs before routing decisions, so the next
     * roles still get their 403 regardless.
     */
    private static function successStatusFor(string $method): int
    {
        return match ($method) {
            'GET' => 200,
            'POST' => 201,
            'DELETE' => 204,
            default => 200,
        };
    }
}
