<?php

declare(strict_types=1);

// Catálogos uniformes (RF-CAT-001, ADR-15): el CÓDIGO pasa a ser clave
// natural de TODOS los catálogos del registro — antes solo lo declaraban
// los catálogos «con código» y siete tablas quedaban sin él
// (educational_levels, beneficiary_types, races, positions,
// pension_regimes, payment_types, income_concepts), de modo que sus
// listados NO devolvían el campo (corrección de usuario, Task 31).
//
// La columna llega NULLABLE + índice único: las filas existentes solo
// reciben código cuando su nombre coincide con las entradas de
// referencia del CatalogsSeeder (misma tabla de códigos), de forma que
// el upsert por código del seeder refresca en vez de duplicar; la
// exigencia de código para ALTAS nuevas vive en la capa de aplicación
// (CatalogDefinition::storeRules), no en el esquema.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Entradas de referencia sembradas por nombre: nombre => código.
     * Espejo EXACTO de las filas del CatalogsSeeder para que la
     * re-siembra por código sea idempotente sobre bases desarrolladas
     * con el esquema anterior.
     *
     * @var array<string, array<string, string>>
     */
    private const REFERENCE_CODES = [
        'educational_levels' => [
            'Primaria' => 'PRIM',
            'Secundaria Básica' => 'SECB',
            'Técnico Medio' => 'TMED',
            'Preuniversitario' => 'PRE',
            'Nivel Superior' => 'SUP',
        ],
        'beneficiary_types' => [
            'Titular' => 'TIT',
            'Viuda/o' => 'VIU',
            'Huérfano' => 'HRF',
            'Otro' => 'OTR',
        ],
        'races' => [
            'Blanca' => 'BLA',
            'Negra' => 'NEG',
            'Mestiza o Mulata' => 'MUL',
            'China' => 'CHN',
            'Otra' => 'OTR',
        ],
        'positions' => [
            'Jefe de Departamento' => 'JDEPT',
            'Jefe de Área' => 'JAREA',
            'Especialista' => 'ESP',
            'Técnico' => 'TEC',
            'Auxiliar de Servicios' => 'ASERV',
        ],
        'pension_regimes' => [
            'General' => 'GEN',
        ],
        'payment_types' => [
            'Abono bancario' => 'ABN',
            'Cheque' => 'CHQ',
            'Efectivo' => 'EFE',
        ],
        'income_concepts' => [
            'Salario base' => 'SALB',
            'Pagos por resultados' => 'PGR',
        ],
    ];

    public function up(): void
    {
        foreach (array_keys(self::REFERENCE_CODES) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('code', 10)->nullable()->after('id');
                $blueprint->unique('code');
            });
        }

        foreach (self::REFERENCE_CODES as $table => $codes) {
            foreach ($codes as $name => $code) {
                DB::table($table)
                    ->where('name', $name)
                    ->whereNull('code')
                    ->update(['code' => $code]);
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::REFERENCE_CODES) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropUnique([$table.'_code_unique']);
                $blueprint->dropColumn('code');
            });
        }
    }
};
