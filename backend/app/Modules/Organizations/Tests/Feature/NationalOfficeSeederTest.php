<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Tests\Feature;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\Municipality;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\OfficeType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Province;
use App\Modules\Organizations\Infrastructure\Persistence\Models\Office;
use Database\Seeders\CatalogsSeeder;
use Database\Seeders\CubaGeographySeeder;
use Database\Seeders\NationalOfficeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * National office seeder (rule 7 of the territorial structure): the
 * application starts with the single national office already in
 * place — the root every provincial office depends on — seeded in
 * the capital province. Idempotent like every reference seeder, and
 * the seeded root immediately unblocks the registration of
 * provincial offices through the API.
 */
final class NationalOfficeSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_national_office_at_the_start(): void
    {
        $this->seed(CubaGeographySeeder::class);
        $this->seed(CatalogsSeeder::class);
        $this->seed(NationalOfficeSeeder::class);

        $offices = Office::query()->get();

        $this->assertCount(1, $offices);

        $national = $offices->firstOrFail();
        $nationalType = OfficeType::query()->where('code', 'NAC')->firstOrFail();
        $habana = Province::query()->where('code', '03')->firstOrFail();
        $plaza = Municipality::query()
            ->where('province_id', $habana->id)
            ->where('code', '02')
            ->firstOrFail();

        $this->assertSame($nationalType->id, $national->office_type_id);
        $this->assertSame($habana->id, $national->province_id);
        $this->assertSame($plaza->id, $national->municipality_id);
        $this->assertSame('Plaza de la Revolución', $plaza->name);
        $this->assertSame(NationalOfficeSeeder::ADDRESS, $national->address);
        $this->assertNull($national->parent_office_id);
    }

    public function test_seeding_is_idempotent(): void
    {
        $this->seed(CubaGeographySeeder::class);
        $this->seed(CatalogsSeeder::class);
        $this->seed(NationalOfficeSeeder::class);
        $this->seed(NationalOfficeSeeder::class);

        $this->assertSame(1, Office::query()->count());
    }

    public function test_recreates_the_national_office_when_it_was_deactivated(): void
    {
        $this->seed(CubaGeographySeeder::class);
        $this->seed(CatalogsSeeder::class);
        $this->seed(NationalOfficeSeeder::class);

        Office::query()->firstOrFail()->delete();
        $this->seed(NationalOfficeSeeder::class);

        // The seeder only sees active offices: the country never
        // starts without its national root.
        $this->assertSame(1, Office::query()->count());
        $this->assertDatabaseCount('offices', 2);
    }

    public function test_the_seeded_root_unblocks_provincial_registration(): void
    {
        $this->seed(CubaGeographySeeder::class);
        $this->seed(CatalogsSeeder::class);
        $this->seed(NationalOfficeSeeder::class);

        $province = Province::query()->where('code', '12')->firstOrFail();
        $municipality = Municipality::query()
            ->where('province_id', $province->id)
            ->where('code', '01')
            ->firstOrFail();
        $national = Office::query()->firstOrFail();
        $provincialType = OfficeType::query()->where('code', 'PRO')->firstOrFail();

        $this->actingAsRole('admin');

        $this->postJson('/api/v1/offices', [
            'office_type_id' => $provincialType->id,
            'province_id' => $province->id,
            'municipality_id' => $municipality->id,
            'address' => 'Provincial Holguín',
        ])->assertCreated()
            ->assertJsonPath('data.parent_office_id', $national->id)
            ->assertJsonPath('data.type.code', 'PRO');
    }
}
