<?php

declare(strict_types=1);

/**
 * Smoke HTTP del ciclo de vida del expediente (SGP-34, corrección de
 * usuario) contra la BD principal (sgp) ya sembrada:
 *
 *  - DELETE /pension-cases/{id}: soft delete SOLO en submitted — la
 *    fila sobrevive con deleted_at (RN-001), los subregistros quedan
 *    físicos, el detalle pasa a 404, el segundo DELETE también 404 y
 *    la reservación de expediente-abierto-por-persona se LIBERA (el
 *    re-alta del mismo solicitante responde 201); la eliminación
 *    aterriza en la bitácora con los valores previos (ADR-19).
 *  - PUT /pension-cases/{id}: edición de los campos propios (salario
 *    último y fecha de solicitud) con advertencias; el PROMOVENTE es
 *    INMUTABLE — todo campo de la esfera de la persona (applicant,
 *    filer, par rebelde, internacionalista, par de contacto, fecha de
 *    desvinculación) responde 422 prohibido; los campos de ciclo de
 *    vida (office_id, number, status) también 422; semántica PATCH
 *    (la omisión nunca arranca el valor); la entidad inactiva y la
 *    referencia de catálogo desconocida responden 422.
 *
 * Ejecutar: DB_DATABASE=sgp php scripts/smoke_case_lifecycle.php
 * (requiere la BD sembrada: DB_DATABASE=sgp php artisan migrate:fresh --seed)
 */

require __DIR__.'/../backend/vendor/autoload.php';

$app = require __DIR__.'/../backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Modules\Catalogs\Infrastructure\Persistence\Models\EntityType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\EducationalLevel;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Municipality;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\OccupationalCategory;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\OfficeType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Organization;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\PensionRegime;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\PensionType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Position;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Province;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\ScientificCategory;
use App\Modules\Organizations\Infrastructure\Persistence\Models\Entity;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\PensionCase;
use App\Modules\People\Infrastructure\Persistence\Models\Person;
use Database\Factories\PersonFactory;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if (! $ok) {
        $failures++;
    }
    echo ($ok ? '  OK  ' : '  FAIL')." {$label}".($detail !== '' ? " — {$detail}" : '')."\n";
}

function detail(object $response): string
{
    $body = (string) $response->body();

    return 'status '.$response->status().': '.mb_substr($body, 0, 220);
}

$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:8476', __DIR__.'/../backend/public/index.php'],
    [1 => ['file', '/dev/null', 'w']],
    $pipes,
);

usleep(500000);

try {
    $login = Http::timeout(15)->post('http://127.0.0.1:8476/api/v1/auth/login', [
        'email' => 'admin@sgp.local',
        'password' => 'password',
    ]);

    if (! $login->successful()) {
        echo "LOGIN no exitoso ({$login->status()}): {$login->body()}\n";
        exit(1);
    }

    $token = $login->json('data.token') ?? $login->json('token');

    if (! is_string($token) || $token === '') {
        echo "Token no hallado en la respuesta de login.\n";
        exit(1);
    }

    $base = function (string $method, string $uri, array $payload = []) use ($token) {
        $verb = strtolower($method);
        $url = "http://127.0.0.1:8476/api/v1{$uri}";

        return $verb === 'get'
            ? Http::timeout(15)->withToken($token)->acceptJson()->get($url)
            : Http::timeout(15)->withToken($token)->acceptJson()->{$verb}($url, $payload);
    };

    $adminId = (int) ($base('GET', '/auth/me')->json('data.id') ?? 0);

    // ---- Fixtures de referencia ----
    $habana = Province::query()->where('code', '03')->firstOrFail();
    $municipality = Municipality::query()
        ->where('province_id', $habana->id)
        ->orderBy('code')
        ->firstOrFail();
    $municipalType = OfficeType::query()->where('code', 'MUN')->firstOrFail();
    $organization = Organization::query()->orderBy('id')->firstOrFail();
    $entityType = EntityType::query()->orderBy('id')->firstOrFail();

    // Jerarquía territorial ADR-31: primero la provincial de La
    // Habana (parent derivado a la nacional), luego una municipal.
    $provincial = $base('POST', '/offices', [
        'office_type_id' => OfficeType::query()->where('code', 'PRO')->firstOrFail()->id,
        'province_id' => $habana->id,
        'municipality_id' => $municipality->id,
        'address' => 'Fumiga lifecycle provincial 03',
    ]);
    check('oficina provincial creada (parent derivado)', $provincial->status() === 201, detail($provincial));

    $municipal = $base('POST', '/offices', [
        'office_type_id' => $municipalType->id,
        'province_id' => $habana->id,
        'municipality_id' => $municipality->id,
        'address' => 'Fumiga lifecycle 03',
    ]);
    $municipalId = (int) ($municipal->json('data.id') ?? 0);
    check('oficina municipal creada', $municipal->status() === 201, detail($municipal));

    $assign = $base('PATCH', "/users/{$adminId}", ['office_id' => $municipalId]);
    check('regla 0: actor asignado a la oficina municipal', $assign->status() === 200, detail($assign));

    $entity = Entity::query()->create([
        'code' => 'LC-'.random_int(1000, 9999),
        'name' => 'Empresa de Fumiga Lifecycle',
        'tax_id_number' => '11'.random_int(100000000, 999999999),
        'organization_id' => $organization->id,
        'province_id' => $habana->id,
        'municipality_id' => $municipality->id,
        'entity_type_id' => $entityType->id,
        'address' => 'Fumiga lifecycle 0',
        'social_purpose' => 'Fumiga del ciclo de vida',
    ]);

    // Identidad aleatoria: la fumiga es re-ejecutable sin colisionar
    // contra corridas previas (la columna identity_number es UNIQUE).
    $birth = sprintf('1961-%02d-%02d', random_int(1, 12), random_int(1, 28));

    $applicant = Person::factory()->create([
        'identity_number' => PersonFactory::identity('M', $birth),
        'birth_date' => $birth,
        'sex' => 'M',
        'address' => 'Calle del lifecycle #1',
    ]);

    $payload = [
        'applicant_person_id' => $applicant->id,
        'employer_entity_id' => $entity->id,
        'position_id' => Position::query()->orderBy('id')->firstOrFail()->id,
        'occupational_category_id' => OccupationalCategory::query()->orderBy('id')->firstOrFail()->id,
        'educational_level_id' => EducationalLevel::query()->orderBy('id')->firstOrFail()->id,
        'scientific_category_id' => ScientificCategory::query()->orderBy('id')->firstOrFail()->id,
        'pension_type_id' => PensionType::query()->orderBy('id')->firstOrFail()->id,
        'pension_regime_id' => PensionRegime::query()->orderBy('id')->firstOrFail()->id,
        'rebel_army_member' => false,
        'internationalist' => true,
        'phone' => '+53 5 555 4321',
        'popular_council' => 'Consejo Popular Fumiga',
        'termination_date' => '2025-07-31',
        'last_salary' => '5000.00',
    ];

    // ---- Alta del expediente víctima ----
    $created = $base('POST', '/pension-cases', $payload);
    $caseId = (int) ($created->json('data.id') ?? 0);
    check('expediente creado (201)', $created->status() === 201 && $caseId > 0, detail($created));

    // ---- PUT: edición de los campos propios ----
    $edited = $base('PUT', "/pension-cases/{$caseId}", [
        'last_salary' => '6200.00',
        'requested_at' => now()->toDateString(),
    ]);
    check('PUT edita el salario último (200, 6200.00)', $edited->status() === 200
        && $edited->json('data.last_salary') === '6200.00', detail($edited));
    check('PUT devuelve el sobre de advertencias', is_array($edited->json('warnings')), detail($edited));

    // ---- PUT: el promovente es inmutable (422 por campo) ----
    foreach ([
        'applicant_person_id' => 999999,
        'filed_by_person_id' => 999999,
        'rebel_army_member' => true,
        'internationalist' => false,
        'phone' => '+53 5 000 0000',
        'popular_council' => 'Otro consejo',
        'termination_date' => '2026-01-31',
    ] as $field => $value) {
        $rejected = $base('PUT', "/pension-cases/{$caseId}", [$field => $value]);
        $hasError = is_array($rejected->json("errors.{$field}"));
        check("PUT promovente inmutable: {$field} responde 422", $rejected->status() === 422 && $hasError, detail($rejected));
    }

    // ---- PUT: los campos de ciclo de vida también 422 ----
    foreach ([
        'office_id' => $municipalId,
        'number' => '11032600099',
        'status' => 'approved',
    ] as $field => $value) {
        $rejected = $base('PUT', "/pension-cases/{$caseId}", [$field => $value]);
        $hasError = is_array($rejected->json("errors.{$field}"));
        check("PUT ciclo de vida prohibido: {$field} responde 422", $rejected->status() === 422 && $hasError, detail($rejected));
    }

    // ---- PUT: semántica PATCH (la omisión nunca arranca el valor) ----
    $empty = $base('PUT', "/pension-cases/{$caseId}", []);
    check('PUT vacío responde el expediente intacto', $empty->status() === 200
        && $empty->json('data.last_salary') === '6200.00'
        && $empty->json('data.phone') === '+53 5 555 4321'
        && $empty->json('data.internationalist') === true
        && $empty->json('data.termination_date') === '2025-07-31', detail($empty));

    // ---- PUT: probes semánticos espejo del alta ----
    $badEntity = $base('PUT', "/pension-cases/{$caseId}", ['employer_entity_id' => 999999]);
    check('PUT con entidad desconocida responde 422', $badEntity->status() === 422
        && is_array($badEntity->json('errors.employer_entity_id')), detail($badEntity));

    $badCatalog = $base('PUT', "/pension-cases/{$caseId}", ['pension_type_id' => 999999]);
    check('PUT con catálogo desconocido responde 422', $badCatalog->status() === 422
        && is_array($badCatalog->json('errors.pension_type_id')), detail($badCatalog));

    $badDate = $base('PUT', "/pension-cases/{$caseId}", ['requested_at' => now()->addDay()->toDateString()]);
    check('PUT con fecha futura responde 422', $badDate->status() === 422
        && is_array($badDate->json('errors.requested_at')), detail($badDate));

    $badMoney = $base('PUT', "/pension-cases/{$caseId}", ['last_salary' => 'not-a-number']);
    check('PUT con salario inválido responde 422', $badMoney->status() === 422
        && is_array($badMoney->json('errors.last_salary')), detail($badMoney));

    // ---- Subregistro para verificar que sobrevive al soft delete ----
    $salary = $base('POST', "/pension-cases/{$caseId}/salary-records", [
        'year' => 2023, 'earned_salary' => '4800.00',
    ]);
    check('subregistro de salario añadido (201)', $salary->status() === 201, detail($salary));

    // ---- DELETE: soft delete del expediente submitted ----
    $deleted = $base('DELETE', "/pension-cases/{$caseId}");
    check('DELETE responde 200 Case deleted.', $deleted->status() === 200
        && $deleted->json('message') === 'Case deleted.', detail($deleted));

    $trashed = PensionCase::withTrashed()->find($caseId);
    check('soft delete: la fila sobrevive con deleted_at', $trashed !== null
        && $trashed->deleted_at !== null, 'deleted_at='.($trashed->deleted_at?->format('Y-m-d H:i:s') ?? 'NULL'));
    check('soft delete: el subregistro queda físico', $trashed !== null
        && $trashed->salaryRecords()->count() === 1);

    $missing = $base('GET', "/pension-cases/{$caseId}");
    check('detalle del eliminado responde 404', $missing->status() === 404, detail($missing));

    $second = $base('DELETE', "/pension-cases/{$caseId}");
    check('segundo DELETE responde 404', $second->status() === 404, detail($second));

    // ---- La reservación se libera: re-alta del mismo solicitante ----
    $recaptured = $base('POST', '/pension-cases', $payload);
    check('re-alta del mismo solicitante responde 201 (reservación liberada)', $recaptured->status() === 201, detail($recaptured));

    // ---- La eliminación aterriza en la bitácora (ADR-19) ----
    $activity = Activity::query()
        ->where('event', 'deleted')
        ->where('subject_type', PensionCase::class)
        ->where('subject_id', $caseId)
        ->first();
    check('bitácora: evento deleted con los valores previos', $activity !== null
        && ($activity->properties['old']['number'] ?? null) === $trashed?->number,
        'properties.old.number='.($activity->properties['old']['number'] ?? 'NULL'));

    // ---- PUT/DELETE sobre el eliminado: 404 ----
    $editDeleted = $base('PUT', "/pension-cases/{$caseId}", ['last_salary' => '7100.00']);
    check('PUT del eliminado responde 404', $editDeleted->status() === 404, detail($editDeleted));

    // ---- Higiene: restaurar al admin sin oficina ----
    $restore = $base('PATCH', "/users/{$adminId}", ['office_id' => null]);
    check('higiene: admin restaurado sin oficina', $restore->status() === 200, detail($restore));
} finally {
    proc_terminate($server);
    proc_close($server);
}

echo $failures === 0 ? "\nFumiga del ciclo de vida: TODO OK\n" : "\nFumiga del ciclo de vida: {$failures} fallo(s)\n";
exit($failures === 0 ? 0 : 1);
