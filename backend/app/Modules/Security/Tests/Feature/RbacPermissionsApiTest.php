<?php

declare(strict_types=1);

namespace App\Modules\Security\Tests\Feature;

use App\Modules\Security\Domain\Authorization\PermissionMatrix;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * RBAC enforcement on the permission catalog surface (RF-SEG-002,
 * ADR-27): both reads answer to roles.view — the same directory
 * surface as GET /roles, so the Administrador reads the catalog to
 * build grants and the Auditor cross-reads it while resolving
 * bitácora subjects. The expected status per role is DERIVED from
 * the PermissionMatrix, so this suite keeps proving the live matrix
 * instead of a hardcoded copy of it.
 */
final class RbacPermissionsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function permissionEndpointsProvider(): array
    {
        return [
            'permissions index' => ['GET', '/api/v1/permissions', 'roles.view'],
            'permissions show' => ['GET', '/api/v1/permissions/{permission}', 'roles.view'],
        ];
    }

    /**
     * @dataProvider permissionEndpointsProvider
     */
    public function test_every_role_hits_exactly_what_the_matrix_grants(string $method, string $uriTemplate, string $permission): void
    {
        $uri = str_replace('{permission}', 'people.view', $uriTemplate);

        foreach (PermissionMatrix::roles() as $role) {
            $this->refreshAuthenticatedUser($role);

            $response = $this->call($method, $uri);

            $expected = PermissionMatrix::roleHasPermission($role, $permission) ? 200 : 403;

            self::assertSame(
                $expected,
                $response->status(),
                "Role [{$role}] hitting [{$method} {$uri}] expected [{$expected}] for permission [{$permission}].",
            );
        }
    }

    public function test_anonymous_requests_keep_answering_401(): void
    {
        $this->getJson('/api/v1/permissions')->assertUnauthorized();
        $this->getJson('/api/v1/permissions/people.view')->assertUnauthorized();
    }

    public function test_a_user_without_roles_is_forbidden(): void
    {
        $this->refreshAuthenticatedUser(null);

        $this->getJson('/api/v1/permissions')->assertForbidden();
        $this->getJson('/api/v1/permissions/people.view')->assertForbidden();
    }

    private function refreshAuthenticatedUser(?string $role): void
    {
        $this->user = User::factory()->create();

        if ($role !== null) {
            $this->user->assignRole($role);
        }

        $this->actingAs($this->user);
    }
}
