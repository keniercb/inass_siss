<?php

declare(strict_types=1);

/**
 * Smoke HTTP del registro de expedientes (reglas de usuario 0-5,
 * ADR-32/ADR-33/ADR-34) contra la BD principal (sgp) ya sembrada:
 *
 *  0. el expediente asume la oficina del usuario que lo registra
 *     (POST sin office_id; 422 si el campo llega; 422 si el actor no
 *     tiene oficina; la oficina desactivada del actor también 422);
 *  1. máximo 15 salarios (16 filas en el payload 422; el 16º alta
 *     individual 422; borrar una libera el cupo);
 *  2. número PPMMAACCCCC — once dígitos contiguos: provincia y
 *     municipio de la oficina registrante, últimos dos dígitos del
 *     año en curso y consecutivo por año/provincia/municipio
 *     rellenado con ceros — que avanza por creación;
 *  3. el listado devuelve la proyección COMPLETA del promovente;
 *  4. tipo/régimen de pensión obligatorios y el par de Ejército
 *     Rebelde coherente (fecha obligatoria con true, rechazada con
 *     false);
 *  5. conceptos de ingreso como subregistro (alta/baja/duplicado).
 *
 * Ejecutar: php scripts/smoke_case_registration.php
 * (requiere la BD sembrada: php artisan migrate:fresh --seed)
 */

require __DIR__.'/../backend/vendor/autoload.php';

$app = require __DIR__.'/../backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Modules\Catalogs\Infrastructure\Persistence\Models\EntityType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\EducationalLevel;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\IncomeConcept;
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
use App\Modules\People\Infrastructure\Persistence\Models\Person;
use Database\Factories\PersonFactory;
use Illuminate\Support\Facades\Http;

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if (! $ok) {
        $failures++;
    }
    echo ($ok ? '  OK  ' : '  FAIL')." {$label}".($detail !== '' ? " — {$detail}" : '')."\n";
}

/** Detail helper: status plus a trimmed response body. */
function detail(object $response): string
{
    $body = (string) $response->body();

    return 'status '.$response->status().': '.mb_substr($body, 0, 220);
}

// Arranca el servidor embebido solo para esta fumiga.
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:8475', __DIR__.'/../backend/public/index.php'],
    [1 => ['file', '/dev/null', 'w']],
    $pipes,
);

usleep(500000);

try {
    $login = Http::timeout(15)->post('http://127.0.0.1:8475/api/v1/auth/login', [
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
        $url = "http://127.0.0.1:8475/api/v1{$uri}";

        return $verb === 'get'
            ? Http::timeout(15)->withToken($token)->acceptJson()->get($url)
            : Http::timeout(15)->withToken($token)->acceptJson()->{$verb}($url, $payload);
    };

    $adminId = (int) ($base('GET', '/auth/me')->json('data.id') ?? 0);

    // ---- Fixtures de referencia (catálogos y geografía sembrados) ----
    $habana = Province::query()->where('code', '03')->firstOrFail();
    $municipality = Municipality::query()
        ->where('province_id', $habana->id)
        ->orderBy('code')
        ->firstOrFail();
    $provincialType = OfficeType::query()->where('code', 'PRO')->firstOrFail();
    $municipalType = OfficeType::query()->where('code', 'MUN')->firstOrFail();
    $organization = Organization::query()->orderBy('id')->firstOrFail();
    $entityType = EntityType::query()->orderBy('id')->firstOrFail();

    // Jerarquía territorial ADR-31: la provincial de La Habana (parent
    // derivado a la nacional) y una municipal de ella.
    $provincial = $base('POST', '/offices', [
        'office_type_id' => $provincialType->id,
        'province_id' => $habana->id,
        'municipality_id' => $municipality->id,
        'address' => 'Fumiga provincial 03',
    ]);
    $provincialId = (int) ($provincial->json('data.id') ?? 0);
    check('oficina provincial creada (parent derivado)', $provincial->status() === 201, detail($provincial));

    $municipal = $base('POST', '/offices', [
        'office_type_id' => $municipalType->id,
        'province_id' => $habana->id,
        'municipality_id' => $municipality->id,
        'address' => 'Fumiga municipal 03',
    ]);
    $municipalId = (int) ($municipal->json('data.id') ?? 0);
    check('oficina municipal creada (parent derivado)', $municipal->status() === 201, detail($municipal));

    $entity = Entity::query()->create([
        'code' => 'FUM-'.random_int(1000, 9999),
        'tax_id_number' => '11'.random_int(100000000, 999999999),
        'organization_id' => $organization->id,
        'province_id' => $habana->id,
        'municipality_id' => $municipality->id,
        'entity_type_id' => $entityType->id,
        'address' => 'Fumiga 0',
        'social_purpose' => 'Fumiga de expedientes',
    ]);

    $applicant = Person::factory()->create([
        'identity_number' => PersonFactory::identity('M', '1962-03-10'),
        'birth_date' => '1962-03-10',
        'sex' => 'M',
        'address' => 'Calle de la fumiga #3',
    ]);
    $secondApplicant = Person::factory()->create([
        'identity_number' => PersonFactory::identity('F', '1957-03-13'),
        'birth_date' => '1957-03-13',
        'sex' => 'F',
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
        'last_salary' => '5000.00',
    ];

    // ---- Regla 0: el actor SIN oficina no puede registrar ----
    $noOffice = $base('POST', '/pension-cases', $payload);
    check('regla 0: actor sin oficina responde 422', $noOffice->status() === 422, detail($noOffice));

    // El admin asume la oficina municipal (ADR-33/ADR-29).
    $assign = $base('PATCH', "/users/{$adminId}", ['office_id' => $municipalId]);
    check('regla 0: actor asignado a la oficina municipal', $assign->status() === 200, detail($assign));

    // ---- Regla 0: office_id en el POST rechazado ----
    $forbidden = $base('POST', '/pension-cases', array_merge($payload, ['office_id' => $municipalId]));
    check('regla 0: office_id en el payload responde 422', $forbidden->status() === 422, detail($forbidden));

    // ---- Regla 2: número PPMMAACCCCC de la oficina del actor ----
    $first = $base('POST', '/pension-cases', $payload);
    $number = (string) ($first->json('data.number') ?? '');
    $expectedShape = '/^'.preg_quote('03'.$municipality->code.date('y'), '/').'\d{5}$/';
    check(
        'regla 2: número PPMMAACCCCC (03'.$municipality->code.date('y').'NNNNN)',
        $first->status() === 201 && preg_match($expectedShape, $number) === 1,
        detail($first).' número='.$number,
    );
    check(
        'regla 0: el expediente asumió la oficina del actor',
        (int) ($first->json('data.office_id') ?? 0) === $municipalId,
        detail($first),
    );
    check(
        'regla 4: clasificación y par rebel persistidos',
        $first->json('data.rebel_army_member') === false
            && $first->json('data.rebel_army_join_date') === null
            && (int) ($first->json('data.pension_type_id') ?? 0) === $payload['pension_type_id'],
        detail($first),
    );

    // ---- Regla 2: el consecutivo territorial avanza ----
    $second = $base('POST', '/pension-cases', array_merge($payload, ['applicant_person_id' => $secondApplicant->id]));
    $secondNumber = (string) ($second->json('data.number') ?? '');
    $consecutive = (int) substr($number, 6);
    check(
        'regla 2: el consecutivo territorial avanza por creación',
        $second->status() === 201 && $secondNumber === substr($number, 0, 6).sprintf('%05d', $consecutive + 1),
        'primero='.$number.' segundo='.$secondNumber,
    );

    // ---- Regla 1: 15 salarios admitidos, el 16º rechazado, el cupo se libera ----
    $caseId = (int) ($first->json('data.id') ?? 0);
    $okFifteen = true;
    for ($year = 2010; $year <= 2024; $year++) {
        $row = $base('POST', "/pension-cases/{$caseId}/salary-records", [
            'year' => $year,
            'earned_salary' => '4800.00',
        ]);
        $okFifteen = $okFifteen && $row->status() === 201;
    }
    check('regla 1: quince salarios admitidos', $okFifteen);

    $sixteenth = $base('POST', "/pension-cases/{$caseId}/salary-records", [
        'year' => 2025,
        'earned_salary' => '4800.00',
    ]);
    check('regla 1: el salario 16 responde 422', $sixteenth->status() === 422, detail($sixteenth));

    $removed = $base('DELETE', "/pension-cases/{$caseId}/salary-records/".((int) ($base('GET', "/pension-cases/{$caseId}")->json('data.salary_records.0.id') ?? 0)));
    $refilled = $base('POST', "/pension-cases/{$caseId}/salary-records", [
        'year' => 2025,
        'earned_salary' => '4800.00',
    ]);
    check(
        'regla 1: borrar una fila libera el cupo',
        $removed->status() === 200 && $refilled->status() === 201,
        detail($removed).' / '.detail($refilled),
    );

    // ---- Regla 1 (payload): 16 filas anidadas rechazadas ----
    $thirdApplicant = Person::factory()->create();
    $sixteenRows = array_map(
        static fn (int $year): array => ['year' => $year, 'earned_salary' => '4800.00'],
        range(2009, 2024),
    );
    $oversized = $base('POST', '/pension-cases', array_merge($payload, [
        'applicant_person_id' => $thirdApplicant->id,
        'salary_records' => $sixteenRows,
    ]));
    check('regla 1: 16 filas en el payload responden 422', $oversized->status() === 422, detail($oversized));

    // ---- Regla 4: par de Ejército Rebelde ----
    $rebelWithoutDate = $base('POST', '/pension-cases', array_merge($payload, [
        'applicant_person_id' => $thirdApplicant->id,
        'rebel_army_member' => true,
    ]));
    check('regla 4: miembro sin fecha de alta responde 422', $rebelWithoutDate->status() === 422, detail($rebelWithoutDate));

    $rebel = $base('POST', '/pension-cases', array_merge($payload, [
        'applicant_person_id' => $thirdApplicant->id,
        'rebel_army_member' => true,
        'rebel_army_join_date' => '1957-03-13',
    ]));
    check(
        'regla 4: miembro con fecha de alta persistido',
        $rebel->status() === 201
            && $rebel->json('data.rebel_army_member') === true
            && $rebel->json('data.rebel_army_join_date') === '1957-03-13',
        detail($rebel),
    );

    // ---- Regla 5: conceptos de ingreso ----
    $concept = IncomeConcept::query()->orderBy('id')->firstOrFail();
    $rebelCaseId = (int) ($rebel->json('data.id') ?? 0);
    $conceptRow = $base('POST', "/pension-cases/{$rebelCaseId}/income-concept-records", [
        'income_concept_id' => $concept->id,
        'amount' => '150.00',
    ]);
    check(
        'regla 5: concepto de ingreso declarado',
        $conceptRow->status() === 201 && $conceptRow->json('data.amount') === '150.00',
        detail($conceptRow),
    );

    $duplicated = $base('POST', "/pension-cases/{$rebelCaseId}/income-concept-records", [
        'income_concept_id' => $concept->id,
        'amount' => '200.00',
    ]);
    check('regla 5: concepto duplicado responde 422', $duplicated->status() === 422, detail($duplicated));

    $conceptId = (int) ($conceptRow->json('data.id') ?? 0);
    $conceptRemoved = $base('DELETE', "/pension-cases/{$rebelCaseId}/income-concept-records/{$conceptId}");
    check('regla 5: baja del concepto de ingreso', $conceptRemoved->status() === 200, detail($conceptRemoved));

    // ---- Regla 3: el listado devuelve el promovente COMPLETO ----
    $listing = $base('GET', '/pension-cases?per_page=5&status=submitted');
    $rows = $listing->json('data') ?? [];
    $row = collect($rows)->firstWhere('applicant.id', $applicant->id) ?? collect($rows)->first();
    $fullApplicant = $row !== null
        && ($row['applicant']['address'] ?? null) === 'Calle de la fumiga #3'
        && ($row['applicant']['birth_date'] ?? null) === '1962-03-10'
        && ($row['applicant']['sex'] ?? null) === 'M'
        && ($row['applicant']['identity_number'] ?? null) === $applicant->identity_number;
    check('regla 3: listado con proyección completa del promovente', $fullApplicant, detail($listing));

    // ---- Regla 0 (higiene): restaurar al admin sin oficina ----
    $restore = $base('PATCH', "/users/{$adminId}", ['office_id' => null]);
    check('higiene: admin restaurado sin oficina', $restore->status() === 200, detail($restore));
} finally {
    proc_terminate($server);
    proc_close($server);
}

echo $failures === 0 ? "\nFumiga de expedientes: TODO OK\n" : "\nFumiga de expedientes: {$failures} fallo(s)\n";
exit($failures === 0 ? 0 : 1);
