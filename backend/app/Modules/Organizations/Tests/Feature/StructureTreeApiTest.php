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
use Tests\TestCase;

/**
 * Structure consultation /api/v1/entities/tree and /api/v1/offices/tree
 * (RF-ENT-005): the hierarchy renders as a depth-limited tree, and
 * nodes cut at the maximum depth are flagged instead of silently
 * hidden. The per-office expediente count joins in Fase 3 with the
 * PensionCases module (ADR-22).
 */
final class StructureTreeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsRole('admin');

        $province = Province::query()->create(['code' => '12', 'name' => 'Holguín']);
        $municipality = Municipality::query()->create([
            'province_id' => $province->id, 'code' => '01', 'name' => 'Holguín',
        ]);
        $organization = Organization::query()->create(['code' => 'MTSS', 'name' => 'Ministerio de Trabajo y Seguridad Social']);
        $type = EntityType::query()->create(['code' => 'EMP', 'name' => 'Empresa']);

        $base = [
            'organization_id' => $organization->id,
            'province_id' => $province->id, 'municipality_id' => $municipality->id,
            'entity_type_id' => $type->id,
        ];

        $root = Entity::query()->create($base + [
            'code' => 'ENT-0001', 'tax_id_number' => '11000000001',
            'address' => 'Calle 1', 'social_purpose' => 'Raíz',
        ]);
        $child = Entity::query()->create($base + [
            'code' => 'ENT-0002', 'tax_id_number' => '11000000002',
            'address' => 'Calle 2', 'social_purpose' => 'Hija',
            'parent_entity_id' => $root->id,
        ]);
        Entity::query()->create($base + [
            'code' => 'ENT-0003', 'tax_id_number' => '11000000003',
            'address' => 'Calle 3', 'social_purpose' => 'Nieta',
            'parent_entity_id' => $child->id,
        ]);

        $officeType = OfficeType::query()
            ->create(['code' => 'NAC', 'name' => 'Nacional']);

        $national = Office::query()->create([
            'office_type_id' => $officeType->id, 'province_id' => $province->id,
            'municipality_id' => $municipality->id, 'address' => 'Nacional',
        ]);
        Office::query()->create([
            'office_type_id' => $officeType->id, 'province_id' => $province->id,
            'municipality_id' => $municipality->id, 'address' => 'Provincial',
            'parent_office_id' => $national->id,
        ]);
    }

    public function test_requires_authentication(): void
    {
        auth()->logout();

        $this->getJson('/api/v1/entities/tree')->assertUnauthorized();
    }

    public function test_renders_the_entity_hierarchy_as_a_nested_tree(): void
    {
        $this->getJson('/api/v1/entities/tree')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'ENT-0001')
            ->assertJsonPath('data.0.children.0.code', 'ENT-0002')
            ->assertJsonPath('data.0.children.0.children.0.code', 'ENT-0003');
    }

    public function test_renders_the_office_hierarchy_as_a_nested_tree(): void
    {
        $this->getJson('/api/v1/offices/tree')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.address', 'Nacional')
            ->assertJsonPath('data.0.children.0.address', 'Provincial');
    }

    public function test_excludes_deactivated_nodes_from_the_tree(): void
    {
        Entity::query()->where('code', 'ENT-0002')->delete();

        $this->getJson('/api/v1/entities/tree')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.children', []);
    }

    public function test_flags_nodes_cut_at_the_maximum_depth(): void
    {
        // Chain of 7: levels 1..7. The tree serves 5 levels and flags
        // the node at level 5 as having deeper descendants.
        $tip = Entity::query()->where('code', 'ENT-0003')->firstOrFail();
        $previous = $tip->id;
        for ($i = 4; $i <= 7; $i++) {
            $entity = Entity::query()->create([
                'code' => "ENT-000{$i}", 'tax_id_number' => "1100000000{$i}",
                'organization_id' => $tip->organization_id, 'province_id' => $tip->province_id,
                'municipality_id' => $tip->municipality_id, 'entity_type_id' => $tip->entity_type_id,
                'address' => "Calle {$i}",
                'social_purpose' => "Nivel {$i}",
                'parent_entity_id' => $previous,
            ]);
            $previous = $entity->id;
        }

        $response = $this->getJson('/api/v1/entities/tree')->assertOk();

        $node = $response->json('data.0');
        for ($level = 2; $level <= 4; $level++) {
            $node = $node['children'][0];
            $this->assertArrayNotHasKey('deeper', $node);
        }

        // Level 5 is the last rendered node and must announce the cut.
        $node = $node['children'][0];
        $this->assertTrue($node['deeper'] === true);
    }
}
