<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Tests\Feature;

use App\Modules\Catalogs\Application\CatalogRegistry;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Agency;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\AgencyType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Municipality;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Province;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Generic catalog resource /api/v1/catalogs/{type} (RF-CAT-001,
 * RF-CAT-006, ADR-15): the 16 uniform catalogs share one CRUD
 * surface driven by the CatalogRegistry, with database-backed
 * uniqueness (RN-008), immutable codes, logical deactivation blocked
 * by active references, and authorship stamping on every write.
 */
final class CatalogCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Admin role: catalog/settings management is admin-exclusive in
        // the PermissionMatrix (RF-SEG-002), so CRUD suites act as admin.
        $this->user = $this->actingAsRole('admin');
    }

    public function test_index_serves_every_uniform_catalog_with_the_envelope(): void
    {
        foreach (CatalogRegistry::keys() as $type) {
            $response = $this->getJson("/api/v1/catalogs/{$type}");

            $response->assertOk();

            $this->assertSame(
                ['data', 'links', 'meta'],
                array_intersect(array_keys($response->json()), ['data', 'links', 'meta']),
                "Catalog [{$type}] must answer with the RF-API-002 envelope."
            );

            $this->assertIsArray($response->json('data'));
        }
    }

    public function test_unknown_catalog_answers_404(): void
    {
        $this->getJson('/api/v1/catalogs/unknown-thing')->assertNotFound();
    }

    public function test_index_requires_authentication(): void
    {
        auth()->logout();

        $this->getJson('/api/v1/catalogs/races')->assertUnauthorized();
    }

    public function test_store_creates_the_entry_and_stamps_the_author(): void
    {
        $response = $this->postJson('/api/v1/catalogs/pension-types', [
            'code' => 'EDAD',
            'name' => 'Por edad',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.code', 'EDAD')
            ->assertJsonPath('data.name', 'Por edad');

        $id = $response->json('data.id');

        $this->assertDatabaseHas('pension_types', [
            'id' => $id,
            'code' => 'EDAD',
            'name' => 'Por edad',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_store_supports_catalog_specific_columns(): void
    {
        $this->postJson('/api/v1/catalogs/pension-regimes', [
            'name' => 'General',
            'months_per_year' => 12,
            'description' => 'Régimen general',
        ])->assertCreated()
            ->assertJsonPath('data.months_per_year', 12)
            ->assertJsonPath('data.description', 'Régimen general');

        $this->postJson('/api/v1/catalogs/income-concepts', [
            'name' => 'Salario base',
            'applies_base_salary' => true,
        ])->assertCreated()
            ->assertJsonPath('data.applies_base_salary', true);
    }

    public function test_store_rejects_duplicated_natural_keys_with_422(): void
    {
        $this->postJson('/api/v1/catalogs/pension-types', ['code' => 'EDAD', 'name' => 'Por edad'])
            ->assertCreated();

        $this->postJson('/api/v1/catalogs/pension-types', ['code' => 'EDAD', 'name' => 'Otro'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->postJson('/api/v1/catalogs/pension-types', ['code' => 'OTRO', 'name' => 'Por edad'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_store_rejects_invalid_months_per_year(): void
    {
        $this->postJson('/api/v1/catalogs/pension-regimes', [
            'name' => 'Régimen inválido',
            'months_per_year' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('months_per_year');
    }

    public function test_update_renames_entries(): void
    {
        $id = $this->postJson('/api/v1/catalogs/races', ['name' => 'Mestiza'])
            ->assertCreated()
            ->json('data.id');

        $this->patchJson("/api/v1/catalogs/races/{$id}", ['name' => 'Mestiza o Mulata'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Mestiza o Mulata');

        $this->assertDatabaseHas('races', ['id' => $id, 'name' => 'Mestiza o Mulata', 'updated_by' => $this->user->id]);
    }

    public function test_update_rejects_code_changes(): void
    {
        $id = $this->postJson('/api/v1/catalogs/pension-types', ['code' => 'EDAD', 'name' => 'Por edad'])
            ->assertCreated()
            ->json('data.id');

        $this->patchJson("/api/v1/catalogs/pension-types/{$id}", ['code' => 'NUEVO'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        // Sending the same code is a no-op, not an error.
        $this->patchJson("/api/v1/catalogs/pension-types/{$id}", ['code' => 'EDAD'])
            ->assertOk();
    }

    public function test_destroy_deactivates_logically(): void
    {
        $id = $this->postJson('/api/v1/catalogs/races', ['name' => 'China'])
            ->assertCreated()
            ->json('data.id');

        $this->deleteJson("/api/v1/catalogs/races/{$id}")
            ->assertOk()
            ->assertJsonPath('message', 'Catalog entry deactivated.');

        $this->assertSoftDeleted('races', ['id' => $id]);

        // Deactivated entries leave the listing...
        $this->getJson('/api/v1/catalogs/races')
            ->assertOk()
            ->assertJsonMissing(['id' => $id]);

        // ...but can still be resolved by id for historical record.
        $this->getJson("/api/v1/catalogs/races/{$id}")->assertOk();
    }

    public function test_destroy_is_blocked_by_active_references_and_frees_on_deactivation(): void
    {
        $typeId = AgencyType::query()->create(['code' => 'AG', 'name' => 'Agencia'])->id;
        $province = Province::query()->create(['code' => '12', 'name' => 'Holguín']);
        $municipality = Municipality::query()->create([
            'province_id' => $province->id,
            'code' => '01',
            'name' => 'Holguín',
        ]);

        $agency = Agency::query()->create([
            'code' => 'BPA01',
            'name' => 'Agencia BPA',
            'province_id' => $province->id,
            'municipality_id' => $municipality->id,
            'agency_type_id' => $typeId,
        ]);

        $this->deleteJson("/api/v1/catalogs/agency-types/{$typeId}")
            ->assertConflict()
            ->assertJsonPath('message', 'The catalog entry cannot be deactivated while active records reference it: agencies.');

        // Deactivating the dependent frees the reference.
        $this->deleteJson("/api/v1/agencies/{$agency->id}")->assertOk();

        $this->deleteJson("/api/v1/catalogs/agency-types/{$typeId}")
            ->assertOk()
            ->assertJsonPath('message', 'Catalog entry deactivated.');
    }

    public function test_listing_supports_search_sort_and_pagination(): void
    {
        foreach ([['01', 'Alpha'], ['02', 'Beta'], ['03', 'Gamma']] as [$code, $name]) {
            $this->postJson('/api/v1/catalogs/pension-types', ['code' => $code, 'name' => $name])
                ->assertCreated();
        }

        $page = $this->getJson('/api/v1/catalogs/pension-types?search=am&sort=name&order=asc&page=1&per_page=1')
            ->assertOk()
            ->json();

        // "am" matches only "Gamma"; pagination meta honors per_page.
        $this->assertCount(1, $page['data']);
        $this->assertSame('Gamma', $page['data'][0]['name']);
        $this->assertSame(1, $page['meta']['per_page']);
        $this->assertSame(1, $page['meta']['total']);

        $descending = $this->getJson('/api/v1/catalogs/pension-types?sort=name&order=desc')
            ->assertOk()
            ->json('data');

        $this->assertSame('Gamma', $descending[0]['name']);
        $this->assertSame('Alpha', $descending[2]['name']);
    }

    public function test_search_matches_codes_too(): void
    {
        $this->postJson('/api/v1/catalogs/pension-types', ['code' => 'SOB', 'name' => 'Por sobrevivencia'])
            ->assertCreated();

        $this->getJson('/api/v1/catalogs/pension-types?search=SOB')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
