<?php

declare(strict_types=1);

/*
 * Scaffolding generator for the uniform catalog tables (Fase 1, Sprint 2).
 *
 * Single source of truth for the 16 catalogs that share the standard shape
 * (surrogate id + optional code + unique name + optional description +
 * optional extras + authorship + soft delete). Emits one migration and one
 * Eloquent model per catalog. The two catalogs with specific rules
 * (municipalities, agencies) are hand-written: they get dedicated services
 * (RF-CAT-002, RF-CAT-003, RN-04).
 *
 * Regeneration: php scripts/gen-catalog-scaffold.php  (files are committed).
 */

const MIGRATIONS_DIR = __DIR__.'/../backend/database/migrations';
const MODELS_DIR = __DIR__.'/../backend/app/Modules/Catalogs/Infrastructure/Persistence/Models';

/** @var array<string, array{model:string,label:string,code?:int,name:int,desc?:bool,extras?:array<string,string>,check?:string,deps?:list<list<string>>}> */
$spec = [
    'provinces' => ['model' => 'Province', 'label' => 'Provincias', 'code' => 4, 'name' => 80, 'deps' => [['Municipality', 'province_id'], ['Agency', 'province_id']]],
    'agency-types' => ['model' => 'AgencyType', 'label' => 'Tipos de agencia', 'code' => 4, 'name' => 80, 'deps' => [['Agency', 'agency_type_id']]],
    'organizations' => ['model' => 'Organization', 'label' => 'Organismos', 'code' => 10, 'name' => 120],
    'entity-types' => ['model' => 'EntityType', 'label' => 'Tipos de entidad', 'code' => 10, 'name' => 80],
    'office-types' => ['model' => 'OfficeType', 'label' => 'Tipos de oficina', 'code' => 10, 'name' => 80],
    'legal-basis-types' => ['model' => 'LegalBasisType', 'label' => 'Tipos de base legal', 'code' => 10, 'name' => 80],
    'scientific-categories' => ['model' => 'ScientificCategory', 'label' => 'Categorías científicas', 'code' => 10, 'name' => 80],
    'educational-levels' => ['model' => 'EducationalLevel', 'label' => 'Niveles educacionales', 'name' => 80, 'desc' => true],
    'occupational-categories' => ['model' => 'OccupationalCategory', 'label' => 'Categorías ocupacionales', 'code' => 10, 'name' => 80],
    'pension-types' => ['model' => 'PensionType', 'label' => 'Tipos de pensión', 'code' => 10, 'name' => 80],
    'beneficiary-types' => ['model' => 'BeneficiaryType', 'label' => 'Tipos de beneficiario', 'name' => 80, 'desc' => true],
    'races' => ['model' => 'Race', 'label' => 'Razas', 'name' => 80],
    'positions' => ['model' => 'Position', 'label' => 'Cargos', 'name' => 80, 'desc' => true],
    'pension-regimes' => ['model' => 'PensionRegime', 'label' => 'Regímenes de pensión', 'name' => 80, 'desc' => true, 'extras' => ['months_per_year' => 'unsigned_integer'], 'check' => 'months_per_year > 0'],
    'payment-types' => ['model' => 'PaymentType', 'label' => 'Tipos de pago', 'name' => 80, 'desc' => true],
    'income-concepts' => ['model' => 'IncomeConcept', 'label' => 'Conceptos de ingreso', 'name' => 80, 'desc' => true, 'extras' => ['applies_base_salary' => 'boolean']],
];

function migrationColumns(array $entry): string
{
    $lines = [];
    if (isset($entry['code'])) {
        $lines[] = "            \$table->string('code', {$entry['code']})->unique();";
    }
    $lines[] = "            \$table->string('name', {$entry['name']})->unique();";
    if ($entry['desc'] ?? false) {
        $lines[] = "            \$table->string('description', 255)->nullable();";
    }
    foreach ($entry['extras'] ?? [] as $column => $type) {
        $lines[] = $type === 'boolean'
            ? "            \$table->boolean('{$column}')->default(false);"
            : "            \$table->unsignedInteger('{$column}');";
    }

    return implode("\n", $lines);
}

function migrationTemplate(): string
{
    return <<<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
{DB_IMPORT}use Illuminate\Support\Facades\Schema;

/**
 * {LABEL} catalog table (RF-CAT-001, data model sections 5.1/5.2).
 *
 * Natural keys are guaranteed by database constraints (RN-008). The
 * logical deactivation required by RF-CAT-001 uses soft deletes, and
 * authorship columns are stamped by the Shared AuditableObserver
 * (ADR-14) on every create/update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{TABLE}', function (Blueprint $table): void {
            $table->id();
{COLUMNS}
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
{CHECK_BLOCK}
    }

    public function down(): void
    {
        Schema::dropIfExists('{TABLE}');
    }
};

PHP;
}

function modelTemplate(): string
{
    return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;

/**
 * Eloquent model for the {TABLE} catalog ({LABEL}).
 *
 * Conventions (ADR-11): the model lives in the module's Infrastructure
 * layer and business rules live in the module's Application services.
 * The code is immutable after creation and rows are logically
 * deactivated through soft deletes (RF-CAT-001). Authorship is stamped
 * by the Shared AuditableObserver, so this class deliberately imports
 * no Security types (deptrac: Catalogs depends on Shared only).
 *
 * @property int $id
{PROPERTY_DOC}
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $deleted_at
 */
class {MODEL} extends CatalogModel
{
    /** @var list<string> */
    protected $fillable = [
{FILLABLE}
    ];
{CASTS_BLOCK}
}

PHP;
}

$stamp = 130001;
$created = [];
foreach ([MIGRATIONS_DIR, MODELS_DIR] as $dir) {
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}
foreach ($spec as $key => $entry) {
    $table = str_replace('-', '_', $key);
    $model = $entry['model'];

    // ---- migration ------------------------------------------------------
    $migration = migrationTemplate();
    $checkBlock = '';
    $dbImport = '';
    if (isset($entry['check'])) {
        $constraint = 'chk_'.substr($table, 0, 40).'_check';
        $checkBlock = "\n        DB::statement('ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$entry['check']})');";
        $dbImport = "use Illuminate\\Support\\Facades\\DB;\n";    }
    $migration = strtr($migration, [
        '{DB_IMPORT}' => $dbImport,
        '{LABEL}' => $entry['label'],
        '{TABLE}' => $table,
        '{COLUMNS}' => migrationColumns($entry),
        '{CHECK_BLOCK}' => $checkBlock,
    ]);
    $migrationFile = sprintf('%s/2026_09_26_%06d_create_%s_table.php', MIGRATIONS_DIR, $stamp, $table);
    file_put_contents($migrationFile, $migration);
    $created[] = basename($migrationFile);

    // ---- model -----------------------------------------------------------
    $propertyDoc = [];
    $fillable = [];
    $casts = [];
    if (isset($entry['code'])) {
        $propertyDoc[] = " * @property string \$code";
        $fillable[] = "        'code',";
    }
    $propertyDoc[] = " * @property string \$name";
    $fillable[] = "        'name',";
    if ($entry['desc'] ?? false) {
        $propertyDoc[] = " * @property string|null \$description";
        $fillable[] = "        'description',";
    }
    foreach ($entry['extras'] ?? [] as $column => $type) {
        if ($type === 'boolean') {
            $propertyDoc[] = " * @property bool \${$column}";
            $casts[] = "            '{$column}' => 'boolean',";
        } else {
            $propertyDoc[] = " * @property int \${$column}";
            $casts[] = "            '{$column}' => 'integer',";
        }
        $fillable[] = "        '{$column}',";
    }
    $fillable[] = "        'created_by',";
    $fillable[] = "        'updated_by',";

    $castsBlock = '';
    if ($casts !== []) {
        $castsBlock = "\n    /**\n     * @return array<string, string>\n     */\n    protected function casts(): array\n    {\n        return array_merge(parent::casts(), [\n".implode("\n", $casts)."\n        ]);\n    }";
    }

    $modelContents = strtr(modelTemplate(), [
        '{LABEL}' => $entry['label'],
        '{TABLE}' => $table,
        '{MODEL}' => $model,
        '{PROPERTY_DOC}' => implode("\n", $propertyDoc),
        '{FILLABLE}' => implode("\n", $fillable),
        '{CASTS_BLOCK}' => $castsBlock,
    ]);
    file_put_contents(sprintf('%s/%s.php', MODELS_DIR, $model), $modelContents);

    $stamp++;
}

echo 'Generated '.count($created)." catalog migrations + ".count($spec)." models\n";
foreach ($created as $file) {
    echo "  migration: {$file}\n";
}
