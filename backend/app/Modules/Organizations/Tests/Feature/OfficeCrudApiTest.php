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
 * Office registration and edition /api/v1/offices (RF-ENT-002):
 * beyond the classic RN-003 (acyclic hierarchy, kept for types
 * outside the territorial triad) and RN-004 (geographic coherence),
 * the CRUD enforces the territorial structure: a single national
 * office, a single provincial office per province, a single
 * municipal office per province and municipality, the forced parent
 * chain (provincial -> national, municipal -> provincial of the
 * same province), the existence prerequisites of the superior
 * offices and the protection of active children when the identity
 * of an office moves. Every write lands in the append-only trail.
 */
final class OfficeCrudApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Province $holguin;

    private Province $santiago;

    private Municipality $holguinMunicipality;

    private Municipality $banesMunicipality;

    private Municipality $santiagoMunicipality;

    private OfficeType $national;

    private OfficeType $provincial;

    private OfficeType $municipal;

    /** Generic type outside the territorial triad: RN-003 path. */
    private OfficeType $regional;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsRole('admin');

        $this->holguin = Province::query()->create(['code' => '12', 'name' => 'Holguín']);
        $this->santiago = Province::query()->create(['code' => '14', 'name' => 'Santiago de Cuba']);
        $this->holguinMunicipality = Municipality::query()->create([
            'province_id' => $this->holguin->id, 'code' => '01', 'name' => 'Holguín',
        ]);
        $this->banesMunicipality = Municipality::query()->create([
            'province_id' => $this->holguin->id, 'code' => '02', 'name' => 'Banes',
        ]);
        $this->santiagoMunicipality = Municipality::query()->create([
            'province_id' => $this->santiago->id, 'code' => '01', 'name' => 'Santiago de Cuba',
        ]);

        $this->national = OfficeType::query()->create(['code' => 'NAC', 'name' => 'Nacional']);
        $this->provincial = OfficeType::query()->create(['code' => 'PRO', 'name' => 'Provincial']);
        $this->municipal = OfficeType::query()->create(['code' => 'MUN', 'name' => 'Municipal']);
        $this->regional = OfficeType::query()->create(['code' => 'REG', 'name' => 'Regional']);
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

    /** Registers the national office and returns its id (rule 7 base). */
    private function registerNational(): int
    {
        return $this->postJson('/api/v1/offices', $this->payload())
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * Registers a provincial office of the given province and returns
     * its id. Requires the national office first (rule 6).
     */
    private function registerProvincial(?Province $province = null): int
    {
        $province ??= $this->holguin;

        return $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->provincial->id,
            'province_id' => $province->id,
            'municipality_id' => ($province->id === $this->holguin->id
                ? $this->holguinMunicipality
                : $this->santiagoMunicipality)->id,
            'address' => "Provincial {$province->name}",
        ]))->assertCreated()->json('data.id');
    }

    public function test_requires_authentication(): void
    {
        auth()->logout();

        $this->postJson('/api/v1/offices', $this->payload())
            ->assertUnauthorized();
    }

    public function test_registers_the_national_office_without_a_parent(): void
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
        $id = $this->registerNational();

        $this->patchJson("/api/v1/offices/{$id}", [
            'municipality_id' => $this->santiagoMunicipality->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('municipality_id');
    }

    public function test_allows_only_one_national_office(): void
    {
        $this->registerNational();

        $this->postJson('/api/v1/offices', $this->payload([
            'address' => 'Segunda sede nacional',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('office_type_id');
    }

    public function test_allows_only_one_provincial_office_per_province(): void
    {
        $this->registerNational();
        $this->registerProvincial($this->holguin);

        // A second provincial office of the same province is refused.
        $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->provincial->id,
            'address' => 'Otra provincial Holguín',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('office_type_id');

        // Other provinces keep their own slot.
        $this->registerProvincial($this->santiago);
        $this->assertDatabaseCount('offices', 3);
    }

    public function test_allows_only_one_municipal_office_per_province_and_municipality(): void
    {
        $this->registerNational();
        $this->registerProvincial($this->holguin);

        $municipalPayload = fn (): array => $this->payload([
            'office_type_id' => $this->municipal->id,
            'address' => 'Municipal Holguín',
        ]);

        $this->postJson('/api/v1/offices', $municipalPayload())->assertCreated();

        // Same province and municipality: refused.
        $this->postJson('/api/v1/offices', $municipalPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('office_type_id');

        // Same province, different municipality: allowed.
        $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->municipal->id,
            'municipality_id' => $this->banesMunicipality->id,
            'address' => 'Municipal Banes',
        ]))->assertCreated();
    }

    public function test_provincial_parent_is_forced_to_the_national_office(): void
    {
        $nationalId = $this->registerNational();

        $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->provincial->id,
            'address' => 'Provincial Holguín',
        ]))->assertCreated()
            ->assertJsonPath('data.parent_office_id', $nationalId)
            ->assertJsonPath('data.parent.type.code', 'NAC');
    }

    public function test_provincial_accepts_the_explicit_national_parent(): void
    {
        $nationalId = $this->registerNational();

        $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->provincial->id,
            'parent_office_id' => $nationalId,
            'address' => 'Provincial Holguín',
        ]))->assertCreated()
            ->assertJsonPath('data.parent_office_id', $nationalId);
    }

    public function test_municipal_parent_is_forced_to_the_provincial_office_of_the_province(): void
    {
        $this->registerNational();
        $provincialId = $this->registerProvincial($this->holguin);

        $id = $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->municipal->id,
            'address' => 'Municipal Holguín',
        ]))->assertCreated()
            ->assertJsonPath('data.parent_office_id', $provincialId)
            ->assertJsonPath('data.parent.type.code', 'PRO')
            ->json('data.id');

        // Editing the address keeps the derived parent untouched.
        $this->patchJson("/api/v1/offices/{$id}", [
            'address' => 'Municipal Holguín, nueva dirección',
        ])->assertOk()
            ->assertJsonPath('data.parent_office_id', $provincialId);
    }

    public function test_municipal_accepts_the_explicit_provincial_parent(): void
    {
        $this->registerNational();
        $provincialId = $this->registerProvincial($this->holguin);

        $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->municipal->id,
            'parent_office_id' => $provincialId,
            'address' => 'Municipal Holguín',
        ]))->assertCreated()
            ->assertJsonPath('data.parent_office_id', $provincialId);
    }

    public function test_provincial_registration_requires_the_national_office(): void
    {
        // No national office yet: the provincial one cannot exist.
        $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->provincial->id,
            'address' => 'Provincial Holguín',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('office_type_id');
    }

    public function test_municipal_registration_requires_the_provincial_office_of_the_province(): void
    {
        $this->registerNational();
        // A provincial office exists, but of another province.
        $this->registerProvincial($this->santiago);

        $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->municipal->id,
            'address' => 'Municipal Holguín',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('office_type_id');
    }

    public function test_rejects_a_provincial_parent_that_is_not_the_national_office(): void
    {
        $this->registerNational();
        $provincialHolguinId = $this->registerProvincial($this->holguin);

        $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->provincial->id,
            'province_id' => $this->santiago->id,
            'municipality_id' => $this->santiagoMunicipality->id,
            'parent_office_id' => $provincialHolguinId,
            'address' => 'Provincial Santiago',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('parent_office_id');
    }

    public function test_rejects_a_municipal_parent_that_is_not_the_provincial_office(): void
    {
        $nationalId = $this->registerNational();
        $this->registerProvincial($this->holguin);

        $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->municipal->id,
            'parent_office_id' => $nationalId,
            'address' => 'Municipal Holguín',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('parent_office_id');
    }

    public function test_rejects_a_parent_for_the_national_office(): void
    {
        $nationalId = $this->registerNational();
        $provincialId = $this->registerProvincial($this->holguin);

        // Explicit null is the correct root value for a national office.
        $this->patchJson("/api/v1/offices/{$nationalId}", ['parent_office_id' => null])
            ->assertOk()
            ->assertJsonPath('data.parent_office_id', null);

        // Any concrete parent contradicts the root of the hierarchy.
        $this->patchJson("/api/v1/offices/{$nationalId}", ['parent_office_id' => $provincialId])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_office_id');
    }

    public function test_rejects_explicit_null_parent_on_provincial_update(): void
    {
        $this->registerNational();
        $id = $this->registerProvincial($this->holguin);

        $this->patchJson("/api/v1/offices/{$id}", ['parent_office_id' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_office_id');
    }

    public function test_update_revalidates_the_scope_uniqueness(): void
    {
        $this->registerNational();
        $holguinId = $this->registerProvincial($this->holguin);
        $this->registerProvincial($this->santiago);

        // Relocating Holguín's provincial to Santiago collides with
        // the provincial office already there.
        $this->patchJson("/api/v1/offices/{$holguinId}", [
            'province_id' => $this->santiago->id,
            'municipality_id' => $this->santiagoMunicipality->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('office_type_id');
    }

    public function test_update_moves_a_municipal_office_and_rederives_its_parent(): void
    {
        $this->registerNational();
        $this->registerProvincial($this->holguin);
        $santiagoProvincialId = $this->registerProvincial($this->santiago);

        $id = $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->municipal->id,
            'address' => 'Municipal Holguín',
        ]))->assertCreated()->json('data.id');

        // Moving to a municipality of another province re-derives the
        // parent to that province's provincial office.
        $this->patchJson("/api/v1/offices/{$id}", [
            'province_id' => $this->santiago->id,
            'municipality_id' => $this->santiagoMunicipality->id,
            'address' => 'Municipal Santiago',
        ])->assertOk()
            ->assertJsonPath('data.parent_office_id', $santiagoProvincialId);
    }

    public function test_update_refuses_identity_changes_with_active_children(): void
    {
        $this->registerNational();
        $provincialId = $this->registerProvincial($this->holguin);
        $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->municipal->id,
            'address' => 'Municipal Holguín',
        ]))->assertCreated();

        // The provincial office still has municipal children: its
        // territory cannot move.
        $this->patchJson("/api/v1/offices/{$provincialId}", [
            'province_id' => $this->santiago->id,
            'municipality_id' => $this->santiagoMunicipality->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('province_id');
    }

    public function test_update_refuses_retyping_an_office_with_active_children(): void
    {
        $nationalId = $this->registerNational();
        $this->registerProvincial($this->holguin);

        // The national office still has provincial children: it
        // cannot stop being the national one.
        $this->patchJson("/api/v1/offices/{$nationalId}", [
            'office_type_id' => $this->regional->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('office_type_id');
    }

    public function test_deactivating_the_unique_scope_allows_recreating_it(): void
    {
        $this->registerNational();
        $id = $this->registerProvincial($this->holguin);

        $this->deleteJson("/api/v1/offices/{$id}")->assertOk();

        // Soft-deleted offices leave the uniqueness scope: the
        // province slot is free again.
        $this->registerProvincial($this->holguin);
        $this->assertDatabaseCount('offices', 3);
    }

    public function test_updates_relocation_to_a_coherent_pair(): void
    {
        $id = $this->registerNational();

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

    public function test_generic_types_keep_an_optional_parent_rn03(): void
    {
        $root = $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->regional->id,
            'address' => 'Regional oriente',
        ]))->assertCreated()
            ->assertJsonPath('data.parent_office_id', null)
            ->json('data.id');

        $child = $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->regional->id,
            'municipality_id' => $this->banesMunicipality->id,
            'parent_office_id' => $root,
            'address' => 'Subregional Banes',
        ]))->assertCreated()
            ->assertJsonPath('data.parent_office_id', $root)
            ->json('data.id');

        // Unrooting stays valid for types outside the territorial triad.
        $this->patchJson("/api/v1/offices/{$child}", ['parent_office_id' => null])
            ->assertOk()
            ->assertJsonPath('data.parent_office_id', null);
    }

    public function test_rejects_an_office_as_its_own_parent_rn03(): void
    {
        $id = $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->regional->id,
            'address' => 'Regional oriente',
        ]))->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/offices/{$id}", ['parent_office_id' => $id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_office_id');
    }

    public function test_rejects_a_cycle_in_the_office_tree_rn03(): void
    {
        $root = $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->regional->id,
            'address' => 'Regional oriente',
        ]))->assertCreated()->json('data.id');

        $child = $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->regional->id,
            'parent_office_id' => $root,
            'address' => 'Subregional Banes',
        ]))->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/offices/{$root}", ['parent_office_id' => $child])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_office_id');
    }

    public function test_custom_types_are_not_subject_to_the_territorial_rules(): void
    {
        // Two regional offices in the same province and municipality,
        // both without parent: the territorial uniqueness does not
        // apply to types outside the triad.
        $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->regional->id,
            'address' => 'Regional oriente',
        ]))->assertCreated();

        $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->regional->id,
            'address' => 'Otra regional oriente',
        ]))->assertCreated();

        $this->assertDatabaseCount('offices', 2);
    }

    public function test_deactivates_an_office(): void
    {
        $id = $this->registerNational();

        $this->deleteJson("/api/v1/offices/{$id}")->assertOk();
        $this->getJson("/api/v1/offices/{$id}")->assertNotFound();
    }

    public function test_refuses_to_deactivate_an_office_with_active_children(): void
    {
        $nationalId = $this->registerNational();
        $this->registerProvincial($this->holguin);

        $this->deleteJson("/api/v1/offices/{$nationalId}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_office_id');
    }

    public function test_lists_offices_with_filters(): void
    {
        $this->registerNational();
        $this->registerProvincial($this->holguin);
        $this->postJson('/api/v1/offices', $this->payload([
            'office_type_id' => $this->municipal->id,
            'address' => 'Municipal Holguín',
        ]))->assertCreated();

        $this->getJson("/api/v1/offices?office_type_id={$this->provincial->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/offices?province_id={$this->holguin->id}")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }
}
