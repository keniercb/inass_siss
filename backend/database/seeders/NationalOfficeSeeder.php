<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\Municipality;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\OfficeType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Province;
use App\Modules\Organizations\Infrastructure\Persistence\Models\Office;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The national office that starts the territorial structure (rule 7
 * of the office CRUD, ADR-31): provincial offices need it to exist,
 * so the application never boots without it. Seeded in the capital
 * province — La Habana, Plaza de la Revolución — as the root of the
 * hierarchy, without parent.
 *
 * Idempotent: only creates the office when no active national one
 * exists, so re-running refreshes nothing and a manually deactivated
 * national office (only possible without active children) is
 * restored on the next seed run.
 *
 * PRELIMINARY REFERENCE DATA: the address is a placeholder to be
 * replaced with the official one by the analista (open question
 * P-06).
 */
final class NationalOfficeSeeder extends Seeder
{
    /** Placeholder address of the national headquarters. */
    public const ADDRESS = 'Sede central, La Habana';

    private const PROVINCE_CODE = '03';

    private const MUNICIPALITY_CODE = '02';

    public function run(): void
    {
        DB::transaction(function (): void {
            $type = OfficeType::query()->where('code', 'NAC')->firstOrFail();
            $province = Province::query()->where('code', self::PROVINCE_CODE)->firstOrFail();
            $municipality = Municipality::query()
                ->where('province_id', $province->id)
                ->where('code', self::MUNICIPALITY_CODE)
                ->firstOrFail();

            $exists = Office::query()
                ->where('office_type_id', $type->id)
                ->exists();

            if ($exists) {
                return;
            }

            Office::query()->create([
                'office_type_id' => $type->id,
                'province_id' => $province->id,
                'municipality_id' => $municipality->id,
                'address' => self::ADDRESS,
                'parent_office_id' => null,
            ]);
        });
    }
}
