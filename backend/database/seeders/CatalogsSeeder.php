<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\AgencyType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\BeneficiaryType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\EducationalLevel;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\EntityType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\IncomeConcept;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\LegalBasisType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\OccupationalCategory;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\OfficeType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Organization;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\PaymentType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\PensionRegime;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\PensionType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Position;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Race;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\ScientificCategory;
use Illuminate\Database\Seeder;

/**
 * Reference data for the classifier catalogs (RF-CAT-004).
 *
 * PRELIMINARY REFERENCE DATA: the lists are the ones known from the
 * source analysis and public sources; the official lists must be
 * validated with the analista (open question P-06) and the special
 * pension regimes with the functional area (P-02). The seeders are
 * versionable: adjusting a list never requires a migration.
 *
 * Idempotent: every catalog upserts by its natural key (the code,
 * present in ALL catalogs since the Task 31 correction), so
 * re-running refreshes descriptions without duplicating.
 */
final class CatalogsSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCodeCatalogs();
        $this->seedNameCatalogs();
    }

    private function seedCodeCatalogs(): void
    {
        AgencyType::upsert(
            self::codeRows([
                ['AG', 'Agencia'], ['SU', 'Sucursal'], ['PS', 'Punto de servicio'],
            ]),
            ['code'],
            ['name'],
        );

        Organization::upsert(
            self::codeRows([
                ['MTSS', 'Ministerio de Trabajo y Seguridad Social'],
                ['MINSAP', 'Ministerio de Salud Pública'],
                ['MINED', 'Ministerio de Educación'],
                ['MES', 'Ministerio de Educación Superior'],
                ['MINREX', 'Ministerio de Relaciones Exteriores'],
                ['MINFAR', 'Ministerio de las Fuerzas Armadas Revolucionarias'],
                ['MININT', 'Ministerio del Interior'],
                ['MINJUS', 'Ministerio de Justicia'],
                ['MINCULT', 'Ministerio de Cultura'],
                ['CITMA', 'Ministerio de Ciencia, Tecnología y Medio Ambiente'],
                ['MFP', 'Ministerio de Finanzas y Precios'],
                ['MINAG', 'Ministerio de la Agricultura'],
                ['MINAL', 'Ministerio de la Industria Alimentaria'],
                ['MINEM', 'Ministerio de Energía y Minas'],
                ['MINCIN', 'Ministerio de Comercio Interior'],
                ['MINCEX', 'Ministerio de Comercio Exterior y la Inversión Extranjera'],
                ['MINCOM', 'Ministerio de Comunicaciones'],
                ['MITRANS', 'Ministerio del Transporte'],
                ['MINTUR', 'Ministerio de Turismo'],
                ['MIC', 'Ministerio de la Construcción'],
                ['INRH', 'Instituto Nacional de Recursos Hidráulicos'],
                ['IACC', 'Instituto de Aeronáutica Civil de Cuba'],
                ['ICRT', 'Instituto Cubano de Radio y Televisión'],
                ['BCC', 'Banco Central de Cuba'],
            ]),
            ['code'],
            ['name'],
        );

        EntityType::upsert(
            self::codeRows([
                ['EMP', 'Empresa'], ['UEB', 'Unidad Empresarial de Base'],
                ['UPR', 'Unidad Presupuestada'], ['ORG', 'Organismo'],
            ]),
            ['code'],
            ['name'],
        );

        OfficeType::upsert(
            self::codeRows([
                ['NAC', 'Nacional'], ['PRO', 'Provincial'], ['MUN', 'Municipal'],
            ]),
            ['code'],
            ['name'],
        );

        LegalBasisType::upsert(
            self::codeRows([
                ['LEY', 'Ley'], ['DLY', 'Decreto-Ley'], ['DEC', 'Decreto'],
                ['RES', 'Resolución'], ['IND', 'Indicación'],
            ]),
            ['code'],
            ['name'],
        );

        ScientificCategory::upsert(
            self::codeRows([
                ['IT', 'Investigador Titular'], ['IA', 'Investigador Auxiliar'],
                ['PT', 'Profesor Titular'], ['PA', 'Profesor Auxiliar'],
            ]),
            ['code'],
            ['name'],
        );

        OccupationalCategory::upsert(
            self::codeRows([
                ['DIR', 'Dirigente'], ['ESP', 'Especialista'], ['TEC', 'Técnico'],
                ['OBR', 'Obrero'], ['SER', 'Servicios'],
            ]),
            ['code'],
            ['name'],
        );

        PensionType::upsert(
            self::codeRows([
                ['EDAD', 'Por edad'], ['INV', 'Por invalidez'], ['SOB', 'Por sobrevivencia'],
            ]),
            ['code'],
            ['name'],
        );
    }

    private function seedNameCatalogs(): void
    {
        EducationalLevel::upsert(
            self::codedRows([
                ['PRIM', 'Primaria', 'Enseñanza primaria'],
                ['SECB', 'Secundaria Básica', 'Enseñanza secundaria básica'],
                ['TMED', 'Técnico Medio', 'Nivel técnico medio'],
                ['PRE', 'Preuniversitario', 'Enseñanza preuniversitaria'],
                ['SUP', 'Nivel Superior', 'Educación superior universitaria'],
            ]),
            ['code'],
            ['name', 'description'],
        );

        BeneficiaryType::upsert(
            self::codedRows([
                ['TIT', 'Titular', 'Beneficiario titular de la pensión'],
                ['VIU', 'Viuda/o', 'Cónyuge sobreviviente'],
                ['HRF', 'Huérfano', 'Hijo menor beneficiario'],
                ['OTR', 'Otro', 'Otro tipo de beneficiario (uso futuro, P-04)'],
            ]),
            ['code'],
            ['name', 'description'],
        );

        Race::upsert(
            self::codeRows([
                ['BLA', 'Blanca'], ['NEG', 'Negra'], ['MUL', 'Mestiza o Mulata'],
                ['CHN', 'China'], ['OTR', 'Otra'],
            ]),
            ['code'],
            ['name'],
        );

        Position::upsert(
            self::codedRows([
                ['JDEPT', 'Jefe de Departamento', null],
                ['JAREA', 'Jefe de Área', null],
                ['ESP', 'Especialista', null],
                ['TEC', 'Técnico', null],
                ['ASERV', 'Auxiliar de Servicios', null],
            ]),
            ['code'],
            ['name', 'description'],
        );

        PensionRegime::upsert(
            [
                [
                    'code' => 'GEN',
                    'name' => 'General',
                    'description' => 'Régimen general: 12 meses de salario por año trabajado. Regímenes especiales pendientes de validación (P-02).',
                    'months_per_year' => 12,
                ],
            ],
            ['code'],
            ['name', 'description', 'months_per_year'],
        );

        PaymentType::upsert(
            self::codedRows([
                ['ABN', 'Abono bancario', 'Abono en cuenta bancaria'],
                ['CHQ', 'Cheque', 'Pago por cheque'],
                ['EFE', 'Efectivo', 'Pago en efectivo'],
            ]),
            ['code'],
            ['name', 'description'],
        );

        IncomeConcept::upsert(
            [
                ['code' => 'SALB', 'name' => 'Salario base', 'description' => 'Salario base devengado', 'applies_base_salary' => true],
                ['code' => 'PGR', 'name' => 'Pagos por resultados', 'description' => 'Pagos por resultados y estimulación', 'applies_base_salary' => false],
            ],
            ['code'],
            ['name', 'description', 'applies_base_salary'],
        );
    }

    /**
     * @param  list<list<string>>  $rows
     * @return list<array<string, string>>
     */
    private static function codeRows(array $rows): array
    {
        return array_map(
            static fn (array $row): array => ['code' => $row[0], 'name' => $row[1]],
            $rows
        );
    }

    /**
     * @param  list<list<string|null>>  $rows
     * @return list<array<string, string|null>>
     */
    private static function codedRows(array $rows): array
    {
        return array_map(
            static fn (array $row): array => ['code' => $row[0], 'name' => $row[1], 'description' => $row[2]],
            $rows
        );
    }
}
