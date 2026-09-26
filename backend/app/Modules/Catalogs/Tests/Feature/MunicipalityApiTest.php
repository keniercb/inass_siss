<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Tests\Feature;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\Agency;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\AgencyType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Municipality;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Province;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Municipalities resource /api/v1/municipalities (RF-CAT-002): the
 * composite natural key (province_id, code) with the nullable
 * special municipality, province filtering, name search and the
 * deactivation guard.
 */
final class MunicipalityApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Province $pinar;

    private Province $habana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->pinar = Province::query()->create(['code' => '01', 'name' => 'Pinar del Río']);
        $this->habana = Province::query()->create(['code' => '03', 'name' => 'La Habana']);
    }

    public function test_index_lists_municipalities_with_their_province(): void
    {
        Municipality::query()->create([
            'province_id' => $this->pinar->id,
            'code' => '05',
            'name' => 'Viñales',
        ]);

        $this->getJson('/api/v1/municipalities')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Viñales')
            ->assertJsonPath('data.0.province.code', '01')
            ->assertJsonPath('data.0.province.name', 'Pinar del Río');
    }

    public function test_index_filters_by_province_and_searches_by_name(): void
    {
        foreach ([
            [$this->pinar->id, '05', 'Viñales'],
            [$this->pinar->id, '01', 'Pinar del Río'],
            [$this->habana->id, '01', 'Playa'],
        ] as [$provinceId, $code, $name]) {
            Municipality::query()->create(compact('provinceId', 'code', 'name') + ['province_id' => $provinceId]);
        }

        $this->getJson("/api/v1/municipalities?province_id={$this->pinar->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/municipalities?search=vinales')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Viñales');
    }

    public function test_creates_a_municipality_within_a_province(): void
    {
        $response = $this->postJson('/api/v1/municipalities', [
            'province_id' => $this->pinar->id,
            'code' => '07',
            'name' => 'La Palma',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.code', '07')
            ->assertJsonPath('data.province.code', '01');

        $this->assertDatabaseHas('municipalities', [
            'code' => '07',
            'name' => 'La Palma',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_creates_the_special_municipality_without_province(): void
    {
        $this->postJson('/api/v1/municipalities', [
            'code' => '99',
            'name' => 'Isla de la Juventud',
        ])->assertCreated()
            ->assertJsonPath('data.province', null);

        $this->assertDatabaseHas('municipalities', [
            'code' => '99',
            'province_id' => null,
        ]);
    }

    public function test_rejects_a_code_already_used_within_the_same_province(): void
    {
        $this->postJson('/api/v1/municipalities', [
            'province_id' => $this->pinar->id,
            'code' => '05',
            'name' => 'Viñales',
        ])->assertCreated();

        $this->postJson('/api/v1/municipalities', [
            'province_id' => $this->pinar->id,
            'code' => '05',
            'name' => 'Otro municipio',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_allows_the_same_code_in_different_provinces(): void
    {
        foreach ([$this->pinar, $this->habana] as $province) {
            $this->postJson('/api/v1/municipalities', [
                'province_id' => $province->id,
                'code' => '01',
                'name' => "Capital {$province->name}",
            ])->assertCreated();
        }

        $this->assertDatabaseCount('municipalities', 2);
    }

    public function test_rejects_an_unknown_province(): void
    {
        $this->postJson('/api/v1/municipalities', [
            'province_id' => 9999,
            'code' => '07',
            'name' => 'La Palma',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('province_id');
    }

    public function test_municipality_code_is_immutable(): void
    {
        $id = Municipality::query()->create([
            'province_id' => $this->pinar->id,
            'code' => '05',
            'name' => 'Viñales',
        ])->id;

        $this->patchJson("/api/v1/municipalities/{$id}", ['code' => '06'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->patchJson("/api/v1/municipalities/{$id}", ['name' => 'Viñales (municipio)'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Viñales (municipio)');
    }

    public function test_deactivation_is_blocked_by_active_agencies(): void
    {
        $municipality = Municipality::query()->create([
            'province_id' => $this->habana->id,
            'code' => '01',
            'name' => 'Playa',
        ]);

        $agency = Agency::query()->create([
            'code' => 'BPA01',
            'name' => 'Agencia Playa',
            'province_id' => $this->habana->id,
            'municipality_id' => $municipality->id,
            'agency_type_id' => AgencyType::query()->create(['code' => 'AG', 'name' => 'Agencia'])->id,
        ]);

        $this->deleteJson("/api/v1/municipalities/{$municipality->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'The catalog entry cannot be deactivated while active records reference it: agencies.');

        $this->deleteJson("/api/v1/agencies/{$agency->id}")->assertOk();

        $this->deleteJson("/api/v1/municipalities/{$municipality->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Municipality deactivated.');
    }
}
