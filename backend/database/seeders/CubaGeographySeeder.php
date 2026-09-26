<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\Municipality;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Province;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Official Cuban geography (RF-CAT-004): 15 provinces and 168
 * municipalities, including the special municipality Isla de la
 * Juventud (province_id null).
 *
 * Idempotent: provinces upsert by code; municipalities upsert by the
 * (province_id, code) natural key, so re-running updates names
 * without duplicating rows. The NULL-province municipality cannot be
 * matched by the composite unique index (SQL NULL semantics), so it
 * is handled explicitly.
 *
 * Codes follow the ONEI ordering (provinces 01-15 west to east plus
 * 99 for the special municipality; municipality codes are local
 * per-province sequences). PRELIMINARY REFERENCE DATA: the official
 * list must be validated with the analista (open question P-06).
 */
final class CubaGeographySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedProvinces();
            $this->seedMunicipalities();
        });
    }

    private function seedProvinces(): void
    {
        $provinces = [
            ['01', 'Pinar del Río'],
            ['02', 'Artemisa'],
            ['03', 'La Habana'],
            ['04', 'Mayabeque'],
            ['05', 'Matanzas'],
            ['06', 'Villa Clara'],
            ['07', 'Cienfuegos'],
            ['08', 'Sancti Spíritus'],
            ['09', 'Ciego de Ávila'],
            ['10', 'Camagüey'],
            ['11', 'Las Tunas'],
            ['12', 'Holguín'],
            ['13', 'Granma'],
            ['14', 'Santiago de Cuba'],
            ['15', 'Guantánamo'],
        ];

        Province::upsert(
            array_map(
                static fn (array $row): array => ['code' => $row[0], 'name' => $row[1]],
                $provinces
            ),
            ['code'],
            ['name'],
        );
    }

    private function seedMunicipalities(): void
    {
        $provinceIds = Province::query()->pluck('id', 'code');

        $rows = [];

        foreach (self::municipalities() as [$provinceCode, $code, $name]) {
            $rows[] = [
                'province_id' => $provinceCode === null ? null : $provinceIds[$provinceCode],
                'code' => $code,
                'name' => $name,
            ];
        }

        // The special municipality (NULL province) never matches the
        // composite unique key, so it is upserted by hand.
        $special = array_pop($rows);
        assert($special !== null);

        $existing = Municipality::withTrashed()
            ->whereNull('province_id')
            ->where('code', $special['code'])
            ->first();

        if ($existing === null) {
            Municipality::query()->create($special);
        } else {
            $existing->forceFill(['name' => $special['name']])->save();
        }

        if ($rows !== []) {
            Municipality::upsert($rows, ['province_id', 'code'], ['name']);
        }
    }

    /**
     * [province code (null = special municipality), local code, name].
     *
     * @return list<list<string|null>>
     */
    private static function municipalities(): array
    {
        return [
            // 01 Pinar del Río (11)
            ['01', '01', 'Pinar del Río'], ['01', '02', 'San Juan y Martínez'], ['01', '03', 'San Luis'],
            ['01', '04', 'Sandino'], ['01', '05', 'Viñales'], ['01', '06', 'La Palma'], ['01', '07', 'Los Palacios'],
            ['01', '08', 'Consolación del Sur'], ['01', '09', 'Guane'], ['01', '10', 'Mantua'],
            ['01', '11', 'Minas de Matahambre'],
            // 02 Artemisa (11)
            ['02', '01', 'Artemisa'], ['02', '02', 'Alquízar'], ['02', '03', 'Bauta'], ['02', '04', 'Bahía Honda'],
            ['02', '05', 'Candelaria'], ['02', '06', 'Caimito'], ['02', '07', 'Guanajay'], ['02', '08', 'Güira de Melena'],
            ['02', '09', 'Mariel'], ['02', '10', 'San Antonio de los Baños'], ['02', '11', 'San Cristóbal'],
            // 03 La Habana (15)
            ['03', '01', 'Playa'], ['03', '02', 'Plaza de la Revolución'], ['03', '03', 'Centro Habana'],
            ['03', '04', 'Habana Vieja'], ['03', '05', 'Regla'], ['03', '06', 'Habana del Este'],
            ['03', '07', 'Guanabacoa'], ['03', '08', 'San Miguel del Padrón'], ['03', '09', 'Diez de Octubre'],
            ['03', '10', 'Cerro'], ['03', '11', 'Marianao'], ['03', '12', 'La Lisa'], ['03', '13', 'Boyeros'],
            ['03', '14', 'Arroyo Naranjo'], ['03', '15', 'Cotorro'],
            // 04 Mayabeque (11)
            ['04', '01', 'San José de las Lajas'], ['04', '02', 'Bejucal'], ['04', '03', 'Jaruco'],
            ['04', '04', 'Santa Cruz del Norte'], ['04', '05', 'Madruga'], ['04', '06', 'Nueva Paz'],
            ['04', '07', 'Güines'], ['04', '08', 'San Nicolás'], ['04', '09', 'Melena del Sur'],
            ['04', '10', 'Batabanó'], ['04', '11', 'Quivicán'],
            // 05 Matanzas (13)
            ['05', '01', 'Matanzas'], ['05', '02', 'Cárdenas'], ['05', '03', 'Ciénaga de Zapata'],
            ['05', '04', 'Jagüey Grande'], ['05', '05', 'Jovellanos'], ['05', '06', 'Colón'], ['05', '07', 'Perico'],
            ['05', '08', 'Limonar'], ['05', '09', 'Los Arabos'], ['05', '10', 'Martí'], ['05', '11', 'Unión de Reyes'],
            ['05', '12', 'Pedro Betancourt'], ['05', '13', 'Calimete'],
            // 06 Villa Clara (13)
            ['06', '01', 'Santa Clara'], ['06', '02', 'Camajuaní'], ['06', '03', 'Cifuentes'], ['06', '04', 'Corralillo'],
            ['06', '05', 'Encrucijada'], ['06', '06', 'Manicaragua'], ['06', '07', 'Placetas'],
            ['06', '08', 'Quemado de Güines'], ['06', '09', 'Ranchuelo'], ['06', '10', 'Remedios'],
            ['06', '11', 'Sagua la Grande'], ['06', '12', 'Santo Domingo'], ['06', '13', 'Caibarién'],
            // 07 Cienfuegos (8)
            ['07', '01', 'Cienfuegos'], ['07', '02', 'Aguada de Pasajeros'], ['07', '03', 'Rodas'], ['07', '04', 'Abreus'],
            ['07', '05', 'Cumanayagua'], ['07', '06', 'Cruces'], ['07', '07', 'Palmira'], ['07', '08', 'Lajas'],
            // 08 Sancti Spíritus (8)
            ['08', '01', 'Sancti Spíritus'], ['08', '02', 'Trinidad'], ['08', '03', 'Cabaiguán'], ['08', '04', 'Yaguajay'],
            ['08', '05', 'Jatibonico'], ['08', '06', 'Taguasco'], ['08', '07', 'La Sierpe'], ['08', '08', 'Fomento'],
            // 09 Ciego de Ávila (10)
            ['09', '01', 'Ciego de Ávila'], ['09', '02', 'Morón'], ['09', '03', 'Chambas'], ['09', '04', 'Bolivia'],
            ['09', '05', 'Ciro Redondo'], ['09', '06', 'Primero de Enero'], ['09', '07', 'Majagua'],
            ['09', '08', 'Venezuela'], ['09', '09', 'Florencia'], ['09', '10', 'Baraguá'],
            // 10 Camagüey (13)
            ['10', '01', 'Camagüey'], ['10', '02', 'Florida'], ['10', '03', 'Nuevitas'], ['10', '04', 'Vertientes'],
            ['10', '05', 'Guáimaro'], ['10', '06', 'Sibanicú'], ['10', '07', 'Minas'], ['10', '08', 'Esmeralda'],
            ['10', '09', 'Carlos Manuel de Céspedes'], ['10', '10', 'Najasa'], ['10', '11', 'Santa Cruz del Sur'],
            ['10', '12', 'Jimaguayú'], ['10', '13', 'Sierra de Cubitas'],
            // 11 Las Tunas (8)
            ['11', '01', 'Las Tunas'], ['11', '02', 'Puerto Padre'], ['11', '03', 'Amancio'], ['11', '04', 'Colombia'],
            ['11', '05', 'Jesús Menéndez'], ['11', '06', 'Majibacoa'], ['11', '07', 'Manatí'], ['11', '08', 'Jobabo'],
            // 12 Holguín (14)
            ['12', '01', 'Holguín'], ['12', '02', 'Banes'], ['12', '03', 'Antilla'], ['12', '04', 'Báguanos'],
            ['12', '05', 'Cacocum'], ['12', '06', 'Calixto García'], ['12', '07', 'Cueto'], ['12', '08', 'Frank País'],
            ['12', '09', 'Gibara'], ['12', '10', 'Urbano Noris'], ['12', '11', 'Mayarí'], ['12', '12', 'Moa'],
            ['12', '13', 'Rafael Freyre'], ['12', '14', 'Sagua de Tánamo'],
            // 13 Granma (13)
            ['13', '01', 'Bayamo'], ['13', '02', 'Manzanillo'], ['13', '03', 'Jiguaní'], ['13', '04', 'Niquero'],
            ['13', '05', 'Pilón'], ['13', '06', 'Media Luna'], ['13', '07', 'Campechuela'], ['13', '08', 'Yara'],
            ['13', '09', 'Río Cauto'], ['13', '10', 'Cauto Cristo'], ['13', '11', 'Guisa'], ['13', '12', 'Buey Arriba'],
            ['13', '13', 'Bartolomé Masó'],
            // 14 Santiago de Cuba (9)
            ['14', '01', 'Santiago de Cuba'], ['14', '02', 'Palma Soriano'], ['14', '03', 'Contramaestre'],
            ['14', '04', 'San Luis'], ['14', '05', 'Songo-La Maya'], ['14', '06', 'Segundo Frente'],
            ['14', '07', 'Mella'], ['14', '08', 'Guamá'], ['14', '09', 'Tercer Frente'],
            // 15 Guantánamo (10)
            ['15', '01', 'Guantánamo'], ['15', '02', 'Baracoa'], ['15', '03', 'Caimanera'], ['15', '04', 'El Salvador'],
            ['15', '05', 'Imías'], ['15', '06', 'Maisí'], ['15', '07', 'Manuel Tames'], ['15', '08', 'Niceto Pérez'],
            ['15', '09', 'San Antonio del Sur'], ['15', '10', 'Yateras'],
            // Special municipality (Isla de la Juventud)
            [null, '99', 'Isla de la Juventud'],
        ];
    }
}
