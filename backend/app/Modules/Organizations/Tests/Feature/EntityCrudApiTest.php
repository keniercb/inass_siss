<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Tests\Feature;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\EntityType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Municipality;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Organization;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Province;
use App\Modules\Organizations\Infrastructure\Persistence\Models\Entity;
use App\Modules\People\Infrastructure\Persistence\Models\Person;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Entity registration and edition /api/v1/entities (RF-ENT-001,
 * RN-003, RN-004): unique code and NIT, optional self-referenced
 * hierarchy kept acyclic by the domain policy, geographic coherence
 * validated in domain plus the composite database key, immutable
 * natural keys after creation and the audited trail with previous
 * values.
 */
final class EntityCrudApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Province $holguin;

    private Province $santiago;

    private Municipality $holguinMunicipality;

    private Municipality $santiagoMunicipality;

    private Organization $organization;

    private EntityType $type;

    protected function setUp(): void
    {
        parent::setUp();

        // Admin holds organizations.manage; role-specific denial lives
        // in RbacOrganizationsApiTest.
        $this->user = $this->actingAsRole('admin');

        $this->holguin = Province::query()->create(['code' => '12', 'name' => 'Holguín']);
        $this->santiago = Province::query()->create(['code' => '14', 'name' => 'Santiago de Cuba']);
        $this->holguinMunicipality = Municipality::query()->create([
            'province_id' => $this->holguin->id, 'code' => '01', 'name' => 'Holguín',
        ]);
        $this->santiagoMunicipality = Municipality::query()->create([
            'province_id' => $this->santiago->id, 'code' => '01', 'name' => 'Santiago de Cuba',
        ]);
        $this->organization = Organization::query()->create(['code' => 'MTSS', 'name' => 'Ministerio de Trabajo y Seguridad Social']);
        $this->type = EntityType::query()->create(['code' => 'EMP', 'name' => 'Empresa']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'ENT-0001',
            'tax_id_number' => '11000012345',
            'organization_id' => $this->organization->id,
            'province_id' => $this->holguin->id,
            'municipality_id' => $this->holguinMunicipality->id,
            'entity_type_id' => $this->type->id,
            'address' => 'Calle 1 #2, Holguín',
            'email' => 'contacto@ent.gob.cu',
            'social_purpose' => 'Servicios técnicos especializados',
        ], $overrides);
    }

    public function test_requires_authentication(): void
    {
        auth()->logout();

        $this->postJson('/api/v1/entities', $this->payload())
            ->assertUnauthorized();
    }

    public function test_registers_an_entity_with_nested_references(): void
    {
        $response = $this->postJson('/api/v1/entities', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.code', 'ENT-0001')
            ->assertJsonPath('data.tax_id_number', '11000012345')
            ->assertJsonPath('data.organization.name', 'Ministerio de Trabajo y Seguridad Social')
            ->assertJsonPath('data.province.code', '12')
            ->assertJsonPath('data.municipality.name', 'Holguín')
            ->assertJsonPath('data.type.name', 'Empresa')
            ->assertJsonPath('data.social_purpose', 'Servicios técnicos especializados')
            ->assertJsonPath('data.parent', null)
            ->assertJsonPath('data.parent_entity_id', null);

        $this->assertDatabaseHas('entities', [
            'code' => 'ENT-0001',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_registers_the_directors_when_declared(): void
    {
        $director = Person::factory()->create();
        $economic = Person::factory()->create();

        $this->postJson('/api/v1/entities', $this->payload([
            'director_person_id' => $director->id,
            'economic_director_person_id' => $economic->id,
        ]))->assertCreated()
            ->assertJsonPath('data.director_person_id', $director->id)
            ->assertJsonPath('data.economic_director_person_id', $economic->id);
    }

    public function test_registration_lands_in_the_audit_trail(): void
    {
        $this->postJson('/api/v1/entities', $this->payload())->assertCreated();

        $this->assertDatabaseHas('activity_log', [
            'event' => 'created',
            'causer_id' => $this->user->id,
            'subject_type' => Entity::class,
        ]);
    }

    #[DataProvider('missingMandatoryProvider')]
    public function test_rejects_missing_mandatory_fields(string $field): void
    {
        $payload = $this->payload();
        unset($payload[$field]);

        $this->postJson('/api/v1/entities', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    /** @return array<string, array{0: string}> */
    public static function missingMandatoryProvider(): array
    {
        return [
            'code' => ['code'],
            'tax_id_number' => ['tax_id_number'],
            'organization_id' => ['organization_id'],
            'province_id' => ['province_id'],
            'municipality_id' => ['municipality_id'],
            'entity_type_id' => ['entity_type_id'],
            'address' => ['address'],
            'social_purpose' => ['social_purpose'],
        ];
    }

    public function test_rejects_a_duplicate_code(): void
    {
        $this->postJson('/api/v1/entities', $this->payload())->assertCreated();

        $this->postJson('/api/v1/entities', $this->payload([
            'tax_id_number' => '11000099999',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_rejects_a_duplicate_tax_id_number(): void
    {
        $this->postJson('/api/v1/entities', $this->payload())->assertCreated();

        $this->postJson('/api/v1/entities', $this->payload([
            'code' => 'ENT-0002',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('tax_id_number');
    }

    public function test_rejects_a_municipality_from_another_province_rn04(): void
    {
        $this->postJson('/api/v1/entities', $this->payload([
            'municipality_id' => $this->santiagoMunicipality->id,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('municipality_id');
    }

    public function test_update_revalidates_rn04_when_relocating(): void
    {
        $id = $this->postJson('/api/v1/entities', $this->payload())
            ->assertCreated()
            ->json('data.id');

        // Moving only the municipality (keeping the old province) is incoherent.
        $this->patchJson("/api/v1/entities/{$id}", [
            'municipality_id' => $this->santiagoMunicipality->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('municipality_id');
    }

    public function test_database_composite_key_backstops_rn04(): void
    {
        $this->expectException(QueryException::class);

        Entity::query()->create([
            'code' => 'ENT-BK',
            'tax_id_number' => '11000077777',
            'organization_id' => $this->organization->id,
            'province_id' => $this->holguin->id,
            'municipality_id' => $this->santiagoMunicipality->id,
            'entity_type_id' => $this->type->id,
            'address' => 'Calle 1 #2',
            'social_purpose' => 'Backstop',
        ]);
    }

    public function test_accepts_a_hierarchy_without_cycles_rn03(): void
    {
        $parent = $this->postJson('/api/v1/entities', $this->payload())->assertCreated()->json('data.id');
        $child = $this->postJson('/api/v1/entities', $this->payload([
            'code' => 'ENT-0002',
            'tax_id_number' => '11000022222',
            'parent_entity_id' => $parent,
        ]))->assertCreated()
            ->assertJsonPath('data.parent_entity_id', $parent)
            ->assertJsonPath('data.parent.code', 'ENT-0001')
            ->json('data.id');

        // Re-parenting the child to the root keeps the tree acyclic.
        $this->patchJson("/api/v1/entities/{$child}", ['parent_entity_id' => null])
            ->assertOk()
            ->assertJsonPath('data.parent_entity_id', null);
    }

    public function test_rejects_an_entity_as_its_own_parent_rn03(): void
    {
        $id = $this->postJson('/api/v1/entities', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->patchJson("/api/v1/entities/{$id}", ['parent_entity_id' => $id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_entity_id');
    }

    public function test_rejects_a_cycle_two_levels_deep_rn03(): void
    {
        $parent = $this->postJson('/api/v1/entities', $this->payload())->assertCreated()->json('data.id');
        $child = $this->postJson('/api/v1/entities', $this->payload([
            'code' => 'ENT-0002',
            'tax_id_number' => '11000022222',
            'parent_entity_id' => $parent,
        ]))->assertCreated()->json('data.id');

        // Making the parent a child of its own child closes 1 -> 2 -> 1.
        $this->patchJson("/api/v1/entities/{$parent}", ['parent_entity_id' => $child])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_entity_id');
    }

    public function test_rejects_a_cycle_three_levels_deep_rn03(): void
    {
        $a = $this->postJson('/api/v1/entities', $this->payload())->assertCreated()->json('data.id');
        $b = $this->postJson('/api/v1/entities', $this->payload(['code' => 'ENT-B', 'tax_id_number' => '11000000002', 'parent_entity_id' => $a]))->assertCreated()->json('data.id');
        $c = $this->postJson('/api/v1/entities', $this->payload(['code' => 'ENT-C', 'tax_id_number' => '11000000003', 'parent_entity_id' => $b]))->assertCreated()->json('data.id');

        // Closing a -> c would create a -> b -> c -> a.
        $this->patchJson("/api/v1/entities/{$a}", ['parent_entity_id' => $c])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_entity_id');
    }

    public function test_code_and_tax_id_number_are_immutable(): void
    {
        $id = $this->postJson('/api/v1/entities', $this->payload())->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/entities/{$id}", ['code' => 'ENT-XXXX'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->patchJson("/api/v1/entities/{$id}", ['tax_id_number' => '11000088888'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tax_id_number');
    }

    public function test_updates_contact_data_with_audit_of_previous_values(): void
    {
        $id = $this->postJson('/api/v1/entities', $this->payload())->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/entities/{$id}", [
            'address' => 'Avenida de los Libertadores #50',
            'phone' => '024 461234',
        ])->assertOk()
            ->assertJsonPath('data.address', 'Avenida de los Libertadores #50')
            ->assertJsonPath('data.phone', '024 461234');

        $entry = Activity::query()
            ->where('subject_type', Entity::class)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($this->user->id, $entry->causer_id);
        $this->assertSame('Calle 1 #2, Holguín', $entry->properties['old']['address'] ?? null);
        $this->assertSame('Avenida de los Libertadores #50', $entry->properties['attributes']['address'] ?? null);
    }

    public function test_deactivates_an_entity_and_reserves_its_natural_keys(): void
    {
        $id = $this->postJson('/api/v1/entities', $this->payload())->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/entities/{$id}")->assertOk();
        $this->getJson("/api/v1/entities/{$id}")->assertNotFound();

        // The code and the NIT stay reserved: a new entity cannot claim them.
        $this->postJson('/api/v1/entities', $this->payload())->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'tax_id_number']);

        // Deactivation is part of the trail.
        $this->assertDatabaseHas('activity_log', [
            'event' => 'deleted',
            'subject_type' => Entity::class,
            'causer_id' => $this->user->id,
        ]);
    }

    public function test_refuses_to_deactivate_an_entity_with_active_children(): void
    {
        $parent = $this->postJson('/api/v1/entities', $this->payload())->assertCreated()->json('data.id');
        $this->postJson('/api/v1/entities', $this->payload([
            'code' => 'ENT-0002', 'tax_id_number' => '11000022222', 'parent_entity_id' => $parent,
        ]))->assertCreated();

        $this->deleteJson("/api/v1/entities/{$parent}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_entity_id');
    }

    public function test_lists_entities_with_search_and_filters(): void
    {
        $this->postJson('/api/v1/entities', $this->payload(['social_purpose' => 'Comercio mayorista de alimentos']))->assertCreated();
        $this->postJson('/api/v1/entities', $this->payload([
            'code' => 'ENT-0002', 'tax_id_number' => '11000022222', 'province_id' => $this->santiago->id,
            'municipality_id' => $this->santiagoMunicipality->id, 'social_purpose' => 'Transporte de carga',
        ]))->assertCreated();

        // Search by NIT fragment (RF-ENT-005).
        $this->getJson('/api/v1/entities?q=22222')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Search by code fragment.
        $this->getJson('/api/v1/entities?q=0002')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Search across social purpose.
        $this->getJson('/api/v1/entities?q=alimentos')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Filter by province.
        $this->getJson("/api/v1/entities?province_id={$this->santiago->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.province.code', '14');
    }

    public function test_lists_are_paginated_with_the_envelope(): void
    {
        $this->getJson('/api/v1/entities?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.current_page', 1);
    }
}
