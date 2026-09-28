<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Tests\Feature;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\EntityType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Municipality;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\OfficeType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Organization;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Province;
use App\Modules\Organizations\Infrastructure\Persistence\Models\Entity;
use App\Modules\Organizations\Infrastructure\Persistence\Models\Office;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Role enforcement over the Organizations surface (RF-SEG-002,
 * ADR-18): reads answer to organizations.view — held by every
 * consultation role — while writes answer to organizations.manage,
 * exclusive to admin in the PermissionMatrix.
 */
final class RbacOrganizationsApiTest extends TestCase
{
    use RefreshDatabase;

    private Entity $entity;

    /** @var array<string, int> */
    private array $refs;

    protected function setUp(): void
    {
        parent::setUp();

        $province = Province::query()->create(['code' => '12', 'name' => 'Holguín']);
        $municipality = Municipality::query()->create([
            'province_id' => $province->id, 'code' => '01', 'name' => 'Holguín',
        ]);
        $organization = Organization::query()->create(['code' => 'MTSS', 'name' => 'Ministerio de Trabajo']);
        $type = EntityType::query()->create(['code' => 'EMP', 'name' => 'Empresa']);
        $officeType = OfficeType::query()->create(['code' => 'NAC', 'name' => 'Nacional']);

        $this->refs = [
            'province_id' => $province->id,
            'municipality_id' => $municipality->id,
            'organization_id' => $organization->id,
            'entity_type_id' => $type->id,
            'office_type_id' => $officeType->id,
        ];

        $this->entity = Entity::query()->create([
            'code' => 'ENT-0001', 'tax_id_number' => '11000000001',
            'organization_id' => $organization->id,
            'province_id' => $province->id, 'municipality_id' => $municipality->id,
            'entity_type_id' => $type->id,
            'address' => 'Calle 1', 'social_purpose' => 'Empresa',
        ]);
        Office::query()->create([
            'office_type_id' => $officeType->id,
            'province_id' => $province->id, 'municipality_id' => $municipality->id,
            'address' => 'Calle 2',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function entityPayload(): array
    {
        return [
            'code' => 'ENT-RBAC',
            'tax_id_number' => '11000000099',
            'organization_id' => $this->refs['organization_id'],
            'province_id' => $this->refs['province_id'],
            'municipality_id' => $this->refs['municipality_id'],
            'entity_type_id' => $this->refs['entity_type_id'],
            'address' => 'Calle RBAC',
            'social_purpose' => 'RBAC',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function officePayload(): array
    {
        return [
            'office_type_id' => $this->refs['office_type_id'],
            'province_id' => $this->refs['province_id'],
            'municipality_id' => $this->refs['municipality_id'],
            'address' => 'Calle RBAC',
        ];
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: int}> */
    public static function roleMatrixProvider(): array
    {
        // [role, verb, path, expected status]. The expectations mirror
        // the PermissionMatrix grants for organizations.* exactly.
        return [
            'admin lists entities' => ['admin', 'GET', '/api/v1/entities', 200],
            'admin stores entities' => ['admin', 'POST', '/api/v1/entities', 201],
            'admin edits entities' => ['admin', 'PATCH', '/api/v1/entities/{id}', 200],
            'admin deletes entities' => ['admin', 'DELETE', '/api/v1/entities/{id}', 200],
            'admin reads the entity tree' => ['admin', 'GET', '/api/v1/entities/tree', 200],
            'admin lists offices' => ['admin', 'GET', '/api/v1/offices', 200],
            'admin stores offices' => ['admin', 'POST', '/api/v1/offices', 201],
            'admin reads the office tree' => ['admin', 'GET', '/api/v1/offices/tree', 200],
            'admin lists signatures' => ['admin', 'GET', '/api/v1/authorized-signatures', 200],

            'director lists entities' => ['director', 'GET', '/api/v1/entities', 200],
            'director reads the entity tree' => ['director', 'GET', '/api/v1/entities/tree', 200],
            'director cannot store entities' => ['director', 'POST', '/api/v1/entities', 403],
            'director cannot edit entities' => ['director', 'PATCH', '/api/v1/entities/{id}', 403],
            'director cannot delete entities' => ['director', 'DELETE', '/api/v1/entities/{id}', 403],

            'specialist lists entities' => ['specialist', 'GET', '/api/v1/entities', 200],
            'specialist cannot store offices' => ['specialist', 'POST', '/api/v1/offices', 403],

            'operator lists entities' => ['operator', 'GET', '/api/v1/entities', 200],
            'operator lists offices' => ['operator', 'GET', '/api/v1/offices', 200],
            'operator cannot store entities' => ['operator', 'POST', '/api/v1/entities', 403],
            'operator cannot delete offices' => ['operator', 'DELETE', '/api/v1/offices/{id}', 403],

            'auditor lists entities' => ['auditor', 'GET', '/api/v1/entities', 200],
            'auditor lists signatures' => ['auditor', 'GET', '/api/v1/authorized-signatures', 200],
            'auditor cannot store signatures' => ['auditor', 'POST', '/api/v1/authorized-signatures', 403],
            'auditor cannot edit entities' => ['auditor', 'PATCH', '/api/v1/entities/{id}', 403],
        ];
    }

    #[DataProvider('roleMatrixProvider')]
    public function test_roles_answer_the_organizations_permission_matrix(string $role, string $verb, string $path, int $status): void
    {
        $this->actingAsRole($role);

        $path = str_replace('{id}', (string) $this->entity->id, $path);

        $payload = match ($path) {
            '/api/v1/entities' => $this->entityPayload(),
            '/api/v1/offices' => $this->officePayload(),
            default => [],
        };

        $this->json($verb, $path, $payload)->assertStatus($status);
    }
}
