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
            'code' => 'GEN',
            'name' => 'General',
            'months_per_year' => 12,
            'description' => 'Régimen general',
        ])->assertCreated()
            ->assertJsonPath('data.months_per_year', 12)
            ->assertJsonPath('data.description', 'Régimen general');

        $this->postJson('/api/v1/catalogs/income-concepts', [
            'code' => 'SALB',
            'name' => 'Salario base',
            'applies_base_salary' => true,
        ])->assertCreated()
            ->assertJsonPath('data.applies_base_salary', true);
    }

    /**
     * Task 38 (user correction, SGP-32): the régimen de jubilación
     * carries an OPTIONAL sector (integer) — EVERY endpoint of the
     * catalog answers with it: store 201, show, listing and PATCH.
     */
    public function test_pension_regimes_carry_the_sector_across_every_endpoint(): void
    {
        $id = $this->postJson('/api/v1/catalogs/pension-regimes', [
            'code' => 'SECT',
            'name' => 'Sectorial',
            'months_per_year' => 12,
            'sector' => 2,
        ])->assertCreated()
            ->assertJsonPath('data.sector', 2)
            ->json('data.id');

        $this->assertDatabaseHas('pension_regimes', ['id' => $id, 'sector' => 2]);

        // Show answers with the sector.
        $this->getJson("/api/v1/catalogs/pension-regimes/{$id}")
            ->assertOk()
            ->assertJsonPath('data.sector', 2);

        // The listing answers with the sector.
        $this->getJson('/api/v1/catalogs/pension-regimes')
            ->assertOk()
            ->assertJsonPath('data.0.sector', 2);

        // PATCH updates the sector and answers with it.
        $this->patchJson("/api/v1/catalogs/pension-regimes/{$id}", ['sector' => 1])
            ->assertOk()
            ->assertJsonPath('data.sector', 1);

        // PATCH without the sector leaves it untouched.
        $this->patchJson("/api/v1/catalogs/pension-regimes/{$id}", ['description' => 'Ajuste'])
            ->assertOk()
            ->assertJsonPath('data.sector', 1);
    }

    public function test_pension_regime_sector_is_optional_and_rejects_non_integers(): void
    {
        $this->postJson('/api/v1/catalogs/pension-regimes', [
            'code' => 'SINSEC',
            'name' => 'Sin sector',
            'months_per_year' => 12,
        ])->assertCreated()
            ->assertJsonPath('data.sector', null);

        $this->assertDatabaseHas('pension_regimes', ['code' => 'SINSEC', 'sector' => null]);

        $this->postJson('/api/v1/catalogs/pension-regimes', [
            'code' => 'MALSEC',
            'name' => 'Sector inválido',
            'months_per_year' => 12,
            'sector' => 'dos',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('sector');
    }

    /**
     * Task 38 (user correction, SGP-32): the tipo de pensión carries
     * the persona fallecida flag — deceased_person, boolean with
     * database DEFAULT false — on EVERY endpoint: an omitted store
     * persists false, an explicit true travels back in the 201, the
     * show, the listing and the PATCH.
     */
    public function test_pension_types_carry_the_deceased_person_flag_across_every_endpoint(): void
    {
        // Omitted flag: the database DEFAULT false answers — never a
        // 422, never a silent drop.
        $plain = $this->postJson('/api/v1/catalogs/pension-types', [
            'code' => 'EDAD',
            'name' => 'Por edad',
        ])->assertCreated()
            ->assertJsonPath('data.deceased_person', false)
            ->json('data.id');

        $this->assertDatabaseHas('pension_types', ['id' => $plain, 'deceased_person' => false]);

        // Explicit flag: a survivor-style type answers true everywhere.
        $id = $this->postJson('/api/v1/catalogs/pension-types', [
            'code' => 'SOB',
            'name' => 'Por sobrevivencia',
            'deceased_person' => true,
        ])->assertCreated()
            ->assertJsonPath('data.deceased_person', true)
            ->json('data.id');

        $this->assertDatabaseHas('pension_types', ['id' => $id, 'deceased_person' => true]);

        $this->getJson("/api/v1/catalogs/pension-types/{$id}")
            ->assertOk()
            ->assertJsonPath('data.deceased_person', true);

        $this->getJson('/api/v1/catalogs/pension-types?search=SOB')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.deceased_person', true);

        // PATCH flips the flag and answers with it.
        $this->patchJson("/api/v1/catalogs/pension-types/{$id}", ['deceased_person' => false])
            ->assertOk()
            ->assertJsonPath('data.deceased_person', false);

        // PATCH without the flag leaves it untouched.
        $this->patchJson("/api/v1/catalogs/pension-types/{$id}", ['name' => 'Por sobrevivencia total'])
            ->assertOk()
            ->assertJsonPath('data.deceased_person', false);

        // Non-boolean values answer 422 on the field.
        $this->postJson('/api/v1/catalogs/pension-types', [
            'code' => 'MALFAL',
            'name' => 'Fallecido inválido',
            'deceased_person' => 'yes',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('deceased_person');
    }

    /**
     * Task 31: every uniform catalog carries a code, so EVERY listing
     * answers with the field — the seven tables that used to be
     * name-only included.
     */
    public function test_every_catalog_listing_returns_the_code_field(): void
    {
        $payloads = [
            'provinces' => ['code' => '99', 'name' => 'Especial'],
            'agency-types' => ['code' => 'OTR', 'name' => 'Otro tipo', 'payment_form' => 'nomina electronica'],
            'organizations' => ['code' => 'OTRORG', 'name' => 'Otro organismo'],
            'entity-types' => ['code' => 'OTRET', 'name' => 'Otro tipo de entidad'],
            'office-types' => ['code' => 'OTROF', 'name' => 'Otro tipo de oficina'],
            'legal-basis-types' => ['code' => 'OTRL', 'name' => 'Otro tipo legal'],
            'scientific-categories' => ['code' => 'OTRC', 'name' => 'Otra categoría'],
            'educational-levels' => ['code' => 'POSTGR', 'name' => 'Postgrado', 'description' => 'Estudios de postgrado'],
            'occupational-categories' => ['code' => 'OTROC', 'name' => 'Otra categoría ocupacional'],
            'pension-types' => ['code' => 'OTRPT', 'name' => 'Otro tipo de pensión'],
            'beneficiary-types' => ['code' => 'PADRE', 'name' => 'Padre', 'description' => 'Beneficiario padre'],
            'races' => ['code' => 'OTRAR', 'name' => 'Otra raza'],
            'positions' => ['code' => 'VICE', 'name' => 'Vicedirector'],
            'pension-regimes' => ['code' => 'ESPR', 'name' => 'Especial', 'months_per_year' => 12],
            'income-concepts' => ['code' => 'OTRIC', 'name' => 'Otros ingresos', 'applies_base_salary' => false],
        ];

        $this->assertSame(CatalogRegistry::keys(), array_keys($payloads), 'Every uniform catalog must be covered.');

        foreach ($payloads as $type => $payload) {
            $this->postJson("/api/v1/catalogs/{$type}", $payload)
                ->assertCreated()
                ->assertJsonPath('data.code', $payload['code']);
        }

        foreach ($payloads as $type => $payload) {
            $rows = $this->getJson("/api/v1/catalogs/{$type}")->assertOk()->json('data');

            $this->assertNotEmpty($rows, "Catalog [{$type}] must list the created entry.");
            $this->assertArrayHasKey('code', $rows[0], "Catalog [{$type}] listing must carry the code field.");
            $this->assertSame($payload['code'], $rows[0]['code'], "Catalog [{$type}] listing code mismatch.");
        }
    }

    /**
     * The formerly name-only catalogs now answer 422 when the code is
     * missing from the payload.
     */
    public function test_store_rejects_a_missing_code_on_the_formerly_name_only_catalogs(): void
    {
        foreach (['races', 'educational-levels', 'positions'] as $type) {
            $this->postJson("/api/v1/catalogs/{$type}", ['name' => 'Sin código'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('code');
        }
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
            'code' => 'MAL',
            'name' => 'Régimen inválido',
            'months_per_year' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('months_per_year');
    }

    public function test_update_renames_entries(): void
    {
        $id = $this->postJson('/api/v1/catalogs/races', ['code' => 'MUL', 'name' => 'Mestiza'])
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
        $id = $this->postJson('/api/v1/catalogs/races', ['code' => 'CHN', 'name' => 'China'])
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

    /**
     * Task 42 (user correction, SGP-36): the payment_types catalog was
     * ELIMINATED — its key answers 404 like any other unknown catalog
     * (the payment form of the collection lives in the agency type).
     */
    public function test_payment_types_is_no_longer_a_catalog(): void
    {
        $this->getJson('/api/v1/catalogs/payment-types')->assertStatus(404);
        $this->postJson('/api/v1/catalogs/payment-types', ['code' => 'TARJ', 'name' => 'Tarjeta'])
            ->assertStatus(404);
    }

    /**
     * Task 42: the payment form of the agency types — the omission of
     * the store falls to the DEFAULT 'tarjeta magnetica' (the
     * deceased_person precedent of Task 38), unknown values answer
     * 422 and the PATCH updates it.
     */
    public function test_the_agency_type_payment_form_falls_to_the_default_when_omitted(): void
    {
        $this->postJson('/api/v1/catalogs/agency-types', ['code' => 'TM', 'name' => 'Agencia de tarjeta'])
            ->assertCreated()
            ->assertJsonPath('data.payment_form', 'tarjeta magnetica');

        $this->postJson('/api/v1/catalogs/agency-types', [
            'code' => 'NE',
            'name' => 'Agencia de nómina',
            'payment_form' => 'nomina electronica',
        ])
            ->assertCreated()
            ->assertJsonPath('data.payment_form', 'nomina electronica');
    }

    public function test_the_agency_type_payment_form_rejects_unknown_values(): void
    {
        $this->postJson('/api/v1/catalogs/agency-types', [
            'code' => 'XX',
            'name' => 'Desconocida',
            'payment_form' => 'Tarjeta Bancaria',
        ])->assertStatus(422)->assertJsonValidationErrors(['payment_form']);
    }

    public function test_the_agency_type_payment_form_is_patchable(): void
    {
        $id = $this->postJson('/api/v1/catalogs/agency-types', ['code' => 'TM', 'name' => 'Agencia de tarjeta'])
            ->assertCreated()
            ->json('data.id');

        $this->patchJson("/api/v1/catalogs/agency-types/{$id}", ['payment_form' => 'nomina electronica'])
            ->assertOk()
            ->assertJsonPath('data.payment_form', 'nomina electronica');

        // Absent PATCH keys never uproot the stored value.
        $this->patchJson("/api/v1/catalogs/agency-types/{$id}", ['name' => 'Agencia de tarjeta y nómina'])
            ->assertOk()
            ->assertJsonPath('data.payment_form', 'nomina electronica');
    }
}
