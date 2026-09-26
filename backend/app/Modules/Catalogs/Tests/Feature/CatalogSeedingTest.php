<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Tests\Feature;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\Municipality;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Province;
use Database\Seeders\CatalogsSeeder;
use Database\Seeders\CubaGeographySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seeders (RF-CAT-004): official Cuban geography and the reference
 * catalogs must load idempotently — re-running refreshes rows without
 * duplicating them, counts match the official figures (15 provinces,
 * 168 municipalities) and the special municipality Isla de la
 * Juventud carries a null province.
 */
final class CatalogSeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_official_geography(): void
    {
        $this->seed(CubaGeographySeeder::class);

        $this->assertSame(15, Province::query()->count());
        $this->assertSame(168, Municipality::query()->count());
    }

    public function test_geography_seeding_is_idempotent(): void
    {
        $this->seed(CubaGeographySeeder::class);
        $this->seed(CubaGeographySeeder::class);

        $this->assertSame(15, Province::query()->count());
        $this->assertSame(168, Municipality::query()->count());
    }

    public function test_the_special_municipality_has_no_province(): void
    {
        $this->seed(CubaGeographySeeder::class);

        $isla = Municipality::query()
            ->whereNull('province_id')
            ->first();

        $this->assertNotNull($isla);
        $this->assertSame('Isla de la Juventud', $isla->name);
        $this->assertSame('99', $isla->code);
    }

    public function test_reference_catalogs_load_with_expected_counts(): void
    {
        $this->seed(CatalogsSeeder::class);

        $expected = [
            'agency_types' => 3,
            'organizations' => 24,
            'entity_types' => 4,
            'office_types' => 3,
            'legal_basis_types' => 5,
            'scientific_categories' => 4,
            'educational_levels' => 5,
            'occupational_categories' => 5,
            'pension_types' => 3,
            'beneficiary_types' => 4,
            'races' => 5,
            'positions' => 5,
            'pension_regimes' => 1,
            'payment_types' => 3,
            'income_concepts' => 2,
        ];

        foreach ($expected as $table => $count) {
            $this->assertSame(
                $count,
                \DB::table($table)->count(),
                "Seeder count mismatch for [{$table}]."
            );
        }
    }

    public function test_reference_catalog_seeding_is_idempotent(): void
    {
        $this->seed(CatalogsSeeder::class);
        $this->seed(CatalogsSeeder::class);

        $this->assertSame(3, \DB::table('pension_types')->count());
        $this->assertSame(5, \DB::table('races')->count());
        $this->assertSame(1, \DB::table('pension_regimes')->count());
    }
}
