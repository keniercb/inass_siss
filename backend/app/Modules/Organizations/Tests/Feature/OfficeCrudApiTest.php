<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Tests\Feature;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\Municipality;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\OfficeType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Province;
use App\Modules\Organizations\Infrastructure\Persistence\Models\Office;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Office registration and edition /api/v1/offices (RF-ENT-002,
 * RN-003, RN-004): the office tree is acyclic, the geographic pair is
 * coherent and the writes land in the append-only trail.
 */
final class OfficeCrudApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Province $holguin;

    private Province $santiago;

    private Municipality $holguinMunicipality;

    private Municipality $santiagoMunicipality;

    private OfficeType $national;

    private OfficeType $municipal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsRole('admin');

        $this->holguin = Province::query()->create(['code' => '12', 'name' => 'Holguín']);
        $this->santiago = Province::query()->create(['code' => '14', 'name' => 'Santiago de Cuba']);
        $this->holguinMunicipality = Municipality::query()->create([
            'province_id' => $this->holguin->id, 'code' => '01', 'name' => 'Holguín',
        ]);
        $this->santiagoMunicipality = Municipality::query()->create([
            'province_id' => $this->santiago->id, 'code' => '01', 'name' => 'Santiago de Cuba',
        ]);
        $this->national = OfficeType::query()->create(['code' => 'NAC', 'name' => 'Nacional']);
        $this->municipal = OfficeType::query()->create(['code' => 'MUN', 'name' => 'Municipal']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'office_type_id' => $this->national->id,
            'province_id' => $this->holguin->id,
            'municipality_id' => $this->holguinMunicipality->id,
            'address' => 'Calle Martí #100, Holguín',
        ], $overrides);
    }

    public function test_requires_authentication(): void
    {
        auth()->logout();

        $this->postJson('/api/v1/offices', $this->payload())
            ->assertUnauthorized();
    }

    public function test_registers_an_office_with_nested_references(): void
    {
        $this->postJson('/api/v1/offices', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.address', 'Calle Martí #100, Holguín')
            ->assertJsonPath('data.type.name', 'Nacional')
            ->assertJsonPath('data.province.code', '12')
            ->assertJsonPath('data.municipality.name', 'Holguín')
            ->assertJsonPath('data.parent_office_id', null);

        $this->assertDatabaseHas('offices', [
            'address' => 'Calle Martí #100, Holguín',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_registration_lands_in_the_audit_trail(): void
    {
        $this->postJson('/api/v1/offices', $this->payload())->assertCreated();

        $this->assertDatabaseHas('activity_log', [
            'event' => 'created',
            'causer_id' => $this->user->id,
            'subject_type' => Office::class,
        ]);
    }

    #[DataProvider('missingMandatoryProvider')]
    public function test_rejects_missing_mandatory_fields(string $field): void
    {
        $payload = $this->payload();
        unset($payload[$field]);

        $this->postJson('/api/v1/offices', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    /** @return array<string, array{0: string}> */
    public static function missingMandatoryProvider(): array
    {
        return [
            'office_type_id' => ['office_type_id'],
            'province_id' => ['province_id'],
            'municipality_id' => ['municipality_id'],
            'address' => ['address'],
        ];
    }

    public function test_rejects_a_municipality_from_another_province_rn04(): void
    {
        $this->postJson('/api/v1/offices', $this->payload([
            'municipality_id' => $this->santiagoMunicipality->id,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('municipality_id');
    }

    public function test_update_revalidates_rn04_when_relocating(): void
    {
        $id = $this->postJson('/api/v1/offices', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->patchJson("/api/v1/offices/{$id}", [
            'municipality_id' => $this->santiagoMunicipality->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('municipality_id');
    }

    public function test_accepts_an_office_hierarchy_rn03(): void
    {
        $parent = $this->postJson('/api/v1/offices', $this->payload())->assertCreated()->json('data.id');

        $child = $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->municipal->id,
            'parent_office_id' => $parent,
        ]))->assertCreated()
            ->assertJsonPath('data.parent_office_id', $parent)
            ->assertJsonPath('data.parent.type.code', 'NAC')
            ->json('data.id');

        // Re-parenting to the root keeps the tree acyclic.
        $this->patchJson("/api/v1/offices/{$child}", ['parent_office_id' => null])
            ->assertOk()
            ->assertJsonPath('data.parent_office_id', null);
    }

    public function test_rejects_an_office_as_its_own_parent_rn03(): void
    {
        $id = $this->postJson('/api/v1/offices', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->patchJson("/api/v1/offices/{$id}", ['parent_office_id' => $id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_office_id');
    }

    public function test_rejects_a_cycle_in_the_office_tree_rn03(): void
    {
        $parent = $this->postJson('/api/v1/offices', $this->payload())->assertCreated()->json('data.id');
        $child = $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->municipal->id,
            'parent_office_id' => $parent,
        ]))->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/offices/{$parent}", ['parent_office_id' => $child])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_office_id');
    }

    public function test_updates_relocation_to_a_coherent_pair(): void
    {
        $id = $this->postJson('/api/v1/offices', $this->payload())->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/offices/{$id}", [
            'province_id' => $this->santiago->id,
            'municipality_id' => $this->santiagoMunicipality->id,
            'address' => 'Calle Heredia #5, Santiago de Cuba',
        ])->assertOk()
            ->assertJsonPath('data.province.code', '14')
            ->assertJsonPath('data.municipality.name', 'Santiago de Cuba');

        $entry = Activity::query()
            ->where('subject_type', Office::class)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('Calle Martí #100, Holguín', $entry->properties['old']['address'] ?? null);
    }

    public function test_deactivates_an_office(): void
    {
        $id = $this->postJson('/api/v1/offices', $this->payload())->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/offices/{$id}")->assertOk();
        $this->getJson("/api/v1/offices/{$id}")->assertNotFound();
    }

    public function test_refuses_to_deactivate_an_office_with_active_children(): void
    {
        $parent = $this->postJson('/api/v1/offices', $this->payload())->assertCreated()->json('data.id');
        $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->municipal->id, 'parent_office_id' => $parent,
        ]))->assertCreated();

        $this->deleteJson("/api/v1/offices/{$parent}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_office_id');
    }

    public function test_lists_offices_with_filters(): void
    {
        $this->postJson('/api/v1/offices', $this->payload())->assertCreated();
        $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->municipal->id,
            'province_id' => $this->santiago->id,
            'municipality_id' => $this->santiagoMunicipality->id,
        ]))->assertCreated();

        $this->getJson("/api/v1/offices?office_type_id={$this->municipal->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/offices?province_id={$this->holguin->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
