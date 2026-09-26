<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Tests\Feature;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\Agency;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\AgencyType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Municipality;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Province;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agencies resource /api/v1/agencies (RF-CAT-003, RN-04): unique
 * code, three validated references, municipality-province coherence
 * enforced both by the service (422 semantics) and by the database
 * composite foreign key (last line of defense).
 */
final class AgencyApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Province $holguin;

    private Province $santiago;

    private Municipality $holguinMunicipality;

    private Municipality $santiagoMunicipality;

    private AgencyType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->holguin = Province::query()->create(['code' => '12', 'name' => 'Holguín']);
        $this->santiago = Province::query()->create(['code' => '14', 'name' => 'Santiago de Cuba']);

        $this->holguinMunicipality = Municipality::query()->create([
            'province_id' => $this->holguin->id,
            'code' => '01',
            'name' => 'Holguín',
        ]);

        $this->santiagoMunicipality = Municipality::query()->create([
            'province_id' => $this->santiago->id,
            'code' => '01',
            'name' => 'Santiago de Cuba',
        ]);

        $this->type = AgencyType::query()->create(['code' => 'AG', 'name' => 'Agencia']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'BPA1201',
            'name' => 'Agencia Holguín',
            'province_id' => $this->holguin->id,
            'municipality_id' => $this->holguinMunicipality->id,
            'agency_type_id' => $this->type->id,
        ], $overrides);
    }

    public function test_creates_an_agency_with_nested_references(): void
    {
        $this->postJson('/api/v1/agencies', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.code', 'BPA1201')
            ->assertJsonPath('data.province.code', '12')
            ->assertJsonPath('data.municipality.name', 'Holguín')
            ->assertJsonPath('data.type.name', 'Agencia');

        $this->assertDatabaseHas('agencies', [
            'code' => 'BPA1201',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_rejects_a_municipality_from_another_province_rn04(): void
    {
        $this->postJson('/api/v1/agencies', $this->payload([
            'municipality_id' => $this->santiagoMunicipality->id,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('municipality_id');
    }

    public function test_update_revalidates_rn04_when_relocating(): void
    {
        $id = $this->postJson('/api/v1/agencies', $this->payload())
            ->assertCreated()
            ->json('data.id');

        // Moving only the municipality (keeping the old province) is incoherent.
        $this->patchJson("/api/v1/agencies/{$id}", [
            'municipality_id' => $this->santiagoMunicipality->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('municipality_id');

        // Moving both together is coherent.
        $this->patchJson("/api/v1/agencies/{$id}", [
            'province_id' => $this->santiago->id,
            'municipality_id' => $this->santiagoMunicipality->id,
        ])->assertOk()
            ->assertJsonPath('data.province.code', '14');
    }

    public function test_rejects_unknown_references(): void
    {
        $this->postJson('/api/v1/agencies', $this->payload(['province_id' => 9999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('province_id');

        $this->postJson('/api/v1/agencies', $this->payload(['municipality_id' => 9999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('municipality_id');

        $this->postJson('/api/v1/agencies', $this->payload(['agency_type_id' => 9999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('agency_type_id');
    }

    public function test_rejects_duplicated_codes(): void
    {
        $this->postJson('/api/v1/agencies', $this->payload())->assertCreated();

        $this->postJson('/api/v1/agencies', $this->payload(['name' => 'Otra agencia']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_agency_code_is_immutable(): void
    {
        $id = $this->postJson('/api/v1/agencies', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->patchJson("/api/v1/agencies/{$id}", ['code' => 'BANDEC'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_index_filters_by_references_and_searches(): void
    {
        Agency::query()->create($this->payload());
        Agency::query()->create($this->payload([
            'code' => 'BPA1401',
            'name' => 'Agencia Santiago',
            'province_id' => $this->santiago->id,
            'municipality_id' => $this->santiagoMunicipality->id,
        ]));

        $this->getJson("/api/v1/agencies?province_id={$this->holguin->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Agencia Holguín');

        $this->getJson('/api/v1/agencies?search=santiago')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'BPA1401');

        $this->getJson("/api/v1/agencies?agency_type_id={$this->type->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_database_composite_foreign_key_blocks_incoherent_inserts(): void
    {
        $this->expectException(QueryException::class);

        // RN-04 as a hard guarantee: the pair cannot be written even
        // by bypassing the application layer.
        DB::table('agencies')->insert([
            'code' => 'RAW001',
            'name' => 'Raw incoherent agency',
            'province_id' => $this->holguin->id,
            'municipality_id' => $this->santiagoMunicipality->id,
            'agency_type_id' => $this->type->id,
        ]);
    }

    public function test_deactivates_agencies(): void
    {
        $id = $this->postJson('/api/v1/agencies', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->deleteJson("/api/v1/agencies/{$id}")
            ->assertOk()
            ->assertJsonPath('message', 'Agency deactivated.');

        $this->assertSoftDeleted('agencies', ['id' => $id]);
    }
}
