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
 * Task 35 (corrección de usuario sobre Task 34): persona por del
 * expediente como REFERENCIA a una persona registrada — el alta
 * recibe filed_by_person_id y el 201, el detalle y el listado devuelven
 * el id más la proyección completa de la persona (422 si el id no
 * existe o está desactivada; la omisión persiste NULL).
 *
 * Task 36 (SGP-30, corrección de usuario): las columnas añadidas en
 * español por las correcciones Task 32-35 pasan al patrón inglés de
 * todas las columnas previas (ADR-03, vinculante para todo el
 * desarrollo): forma_declaracion → declaration_form y persona_por_id
 * → filed_by_person_id.
 *
 * Task 37 (SGP-31, corrección de usuario): el expediente lleva la
 * marca internacionalista del promovente (booleana OBLIGATORIA) y el
 * par de contacto (phone, popular_council — textos opcionales); los
 * subregistros de servicio exigen fecha de fin OBLIGATORIA y
 * ESTRICTAMENTE posterior al inicio, y NINGÚN par de períodos puede
 * solaparse (422 en ambos puntos de entrada, sin vínculos abiertos).
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
        'name' => 'Empresa de Fumiga',
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
    $thirdApplicant = Person::factory()->create([
        'identity_number' => PersonFactory::identity('M', '1950-07-22'),
        'birth_date' => '1950-07-22',
        'sex' => 'M',
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
        // Task 37: marca de internacionalista del promovente —
        // obligatoria en el wire, como rebel_army_member.
        'internationalist' => false,
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

    // ---- Task 37: internacionalista + contacto del promovente ----
    // (tras el sondeo del consecutivo: esta sección consume números
    // y rompería la aritmética primero→segundo si corriera antes)
    $contactApplicant = Person::factory()->create();
    $withFields = $base('POST', '/pension-cases', array_merge($payload, [
        'applicant_person_id' => $contactApplicant->id,
        'internationalist' => true,
        'phone' => '+53 5 555 1234',
        'popular_council' => 'Consejo Popular Playa',
    ]));
    $withFieldsId = (int) ($withFields->json('data.id') ?? 0);
    check(
        'task 37: internacionalista y contacto del promovente recibidos y devueltos (201, detalle y listado)',
        $withFields->status() === 201
            && $withFields->json('data.internationalist') === true
            && ($withFields->json('data.phone') ?? '') === '+53 5 555 1234'
            && ($withFields->json('data.popular_council') ?? '') === 'Consejo Popular Playa'
            && ($base('GET', "/pension-cases/{$withFieldsId}")->json('data.internationalist') ?? null) === true
            && ($base('GET', "/pension-cases/{$withFieldsId}")->json('data.phone') ?? '') === '+53 5 555 1234'
            && ($base('GET', '/pension-cases?applicant_person_id='.$contactApplicant->id)->json('data.0.popular_council') ?? '') === 'Consejo Popular Playa',
        detail($withFields),
    );

    $missingFlag = $payload;
    unset($missingFlag['internationalist']);
    $missingFlagApplicant = Person::factory()->create();
    $noFlag = $base('POST', '/pension-cases', array_merge($missingFlag, [
        'applicant_person_id' => $missingFlagApplicant->id,
    ]));
    check(
        'task 37: internationalist omitida responde 422',
        $noFlag->status() === 422 && isset($noFlag->json('errors')['internationalist']),
        detail($noFlag),
    );

    $oversizedPhoneApplicant = Person::factory()->create();
    $oversizedPhone = $base('POST', '/pension-cases', array_merge($payload, [
        'applicant_person_id' => $oversizedPhoneApplicant->id,
        'phone' => str_repeat('5', 31),
    ]));
    check(
        'task 37: teléfono de 31 caracteres responde 422',
        $oversizedPhone->status() === 422 && isset($oversizedPhone->json('errors')['phone']),
        detail($oversizedPhone),
    );

    $oversizedCouncilApplicant = Person::factory()->create();
    $oversizedCouncil = $base('POST', '/pension-cases', array_merge($payload, [
        'applicant_person_id' => $oversizedCouncilApplicant->id,
        'popular_council' => str_repeat('Consejo Popular ', 9),
    ]));
    check(
        'task 37: consejo popular de 121+ caracteres responde 422',
        $oversizedCouncil->status() === 422 && isset($oversizedCouncil->json('errors')['popular_council']),
        detail($oversizedCouncil),
    );

    // ---- Forma de declaración del tiempo de servicio (Task 33;
    //     columna declaration_form desde Task 36) ----
    // El payload anidado la declara por fila: una Testifical y una
    // omitida (default Documental); un valor desconocido es 422 y no
    // deja nada detrás.
    $nestedPayload = array_merge($payload, [
        'applicant_person_id' => $thirdApplicant->id,
        'service_records' => [
            ['entity_id' => $entity->id, 'start_date' => '1980-01-01', 'end_date' => '1990-12-31', 'declaration_form' => 'Testifical'],
            ['entity_id' => $entity->id, 'start_date' => '2000-01-01', 'end_date' => '2010-12-31'],
        ],
    ]);
    $nested = $base('POST', '/pension-cases', $nestedPayload);
    check(
        'forma de declaración: filas anidadas con Testifical y default Documental',
        $nested->status() === 201
            && ($nested->json('data.service_records.0.declaration_form') ?? '') === 'Testifical'
            && ($nested->json('data.service_records.1.declaration_form') ?? '') === 'Documental',
        detail($nested),
    );
    $nestedInvalid = $base('POST', '/pension-cases', array_merge($payload, [
        'applicant_person_id' => $thirdApplicant->id,
        'service_records' => [
            ['entity_id' => $entity->id, 'start_date' => '1980-01-01', 'end_date' => '1990-12-31', 'declaration_form' => 'Mixta'],
        ],
    ]));
    check(
        'forma de declaración: valor desconocido en el payload anidado responde 422',
        $nestedInvalid->status() === 422 && isset($nestedInvalid->json('errors')['service_records.0.declaration_form']),
        detail($nestedInvalid),
    );

    // ---- Task 37: períodos de servicio cerrados y disjuntos ----
    // (cada sondeo usa promovente recién creado: el sondeo de
    // expediente abierto corre ANTES que el de filas, y un 409
    // enmascararía el 422 esperado)
    $nestedNoEnd = $base('POST', '/pension-cases', array_merge($payload, [
        'applicant_person_id' => Person::factory()->create()->id,
        'service_records' => [
            ['entity_id' => $entity->id, 'start_date' => '1980-01-01'],
        ],
    ]));
    check(
        'task 37: fila anidada sin end_date responde 422',
        $nestedNoEnd->status() === 422 && isset($nestedNoEnd->json('errors')['service_records.0.end_date']),
        detail($nestedNoEnd),
    );

    $nestedSameDay = $base('POST', '/pension-cases', array_merge($payload, [
        'applicant_person_id' => Person::factory()->create()->id,
        'service_records' => [
            ['entity_id' => $entity->id, 'start_date' => '1980-01-01', 'end_date' => '1980-01-01'],
        ],
    ]));
    check(
        'task 37: fila anidada con fin igual al inicio responde 422',
        $nestedSameDay->status() === 422 && isset($nestedSameDay->json('errors')['service_records.0.end_date']),
        detail($nestedSameDay),
    );

    $nestedOverlap = $base('POST', '/pension-cases', array_merge($payload, [
        'applicant_person_id' => Person::factory()->create()->id,
        'service_records' => [
            ['entity_id' => $entity->id, 'start_date' => '1980-01-01', 'end_date' => '1990-12-31'],
            ['entity_id' => $entity->id, 'start_date' => '1985-06-01', 'end_date' => '1995-12-31'],
        ],
    ]));
    check(
        'task 37: filas anidadas solapadas responde 422',
        $nestedOverlap->status() === 422 && isset($nestedOverlap->json('errors')['service_records']),
        detail($nestedOverlap),
    );

    $nestedAdjacent = $base('POST', '/pension-cases', array_merge($payload, [
        'applicant_person_id' => Person::factory()->create()->id,
        'service_records' => [
            ['entity_id' => $entity->id, 'start_date' => '1980-01-01', 'end_date' => '1990-12-31'],
            ['entity_id' => $entity->id, 'start_date' => '1991-01-01', 'end_date' => '2000-12-31'],
        ],
    ]));
    check(
        'task 37: filas anidadas contiguas admitidas',
        $nestedAdjacent->status() === 201 && count($nestedAdjacent->json('data.service_records') ?? []) === 2,
        detail($nestedAdjacent),
    );
    $adjacentCaseId = (int) ($nestedAdjacent->json('data.id') ?? 0);

    // ---- Task 35: persona por del expediente (referencia a una
    // persona REGISTRADA — corrección de usuario sobre Task 34;
    // columna filed_by_person_id desde Task 36) ----
    $fourthApplicant = Person::factory()->create();
    $filer = Person::factory()->create([
        'identity_number' => PersonFactory::identity('F', '1980-07-12'),
        'birth_date' => '1980-07-12',
        'sex' => 'F',
        'first_name' => 'María',
        'first_surname' => 'Fernández',
    ]);
    $filedByCase = $base('POST', '/pension-cases', array_merge($payload, [
        'applicant_person_id' => $fourthApplicant->id,
        'filed_by_person_id' => $filer->id,
    ]));
    $filedByCaseId = (int) ($filedByCase->json('data.id') ?? 0);
    check(
        'persona por: referencia recibida en el alta y devuelta (id + proyección) en el 201 y el detalle',
        $filedByCase->status() === 201
            && (int) ($filedByCase->json('data.filed_by_person_id') ?? 0) === $filer->id
            && (int) ($filedByCase->json('data.filed_by.id') ?? 0) === $filer->id
            && ($filedByCase->json('data.filed_by.first_name') ?? '') === 'María'
            && (int) ($base('GET', "/pension-cases/{$filedByCaseId}")->json('data.filed_by_person_id') ?? 0) === $filer->id
            && ($base('GET', "/pension-cases/{$filedByCaseId}")->json('data.filed_by.identity_number') ?? '') === $filer->identity_number,
        detail($filedByCase),
    );
    check(
        'persona por: el listado filtrado por promovente devuelve el id y la proyección',
        (int) ($base('GET', '/pension-cases?applicant_person_id='.$fourthApplicant->id)->json('data.0.filed_by_person_id') ?? 0) === $filer->id
            && ($base('GET', '/pension-cases?applicant_person_id='.$fourthApplicant->id)->json('data.0.filed_by.first_name') ?? '') === 'María',
    );
    $unknownFiledBy = $base('POST', '/pension-cases', array_merge($payload, [
        'applicant_person_id' => $fourthApplicant->id,
        'filed_by_person_id' => 999999,
    ]));
    check(
        'persona por: un id de persona no registrada responde 422',
        $unknownFiledBy->status() === 422 && isset($unknownFiledBy->json('errors')['filed_by_person_id']),
        detail($unknownFiledBy),
    );
    $deactivatedFiler = Person::factory()->create([
        'identity_number' => PersonFactory::identity('F', '1975-02-03'),
        'birth_date' => '1975-02-03',
        'sex' => 'F',
    ]);
    $deactivatedFiler->delete();
    $deactivatedFiledBy = $base('POST', '/pension-cases', array_merge($payload, [
        'applicant_person_id' => $fourthApplicant->id,
        'filed_by_person_id' => $deactivatedFiler->id,
    ]));
    check(
        'persona por: una persona desactivada responde 422',
        $deactivatedFiledBy->status() === 422 && isset($deactivatedFiledBy->json('errors')['filed_by_person_id']),
        detail($deactivatedFiledBy),
    );

    // ---- Task 37: alta individual de servicio — fin obligatorio,
    //      estrictamente posterior y sin solapamiento ----
    $individualNoEnd = $base('POST', "/pension-cases/{$adjacentCaseId}/service-records", [
        'entity_id' => $entity->id,
        'start_date' => '2005-01-01',
    ]);
    check(
        'task 37: alta individual sin end_date responde 422',
        $individualNoEnd->status() === 422 && isset($individualNoEnd->json('errors')['end_date']),
        detail($individualNoEnd),
    );

    $individualBefore = $base('POST', "/pension-cases/{$adjacentCaseId}/service-records", [
        'entity_id' => $entity->id,
        'start_date' => '2005-01-01',
        'end_date' => '2004-12-31',
    ]);
    check(
        'task 37: fin anterior al inicio responde 422',
        $individualBefore->status() === 422 && isset($individualBefore->json('errors')['end_date']),
        detail($individualBefore),
    );

    $individualSameDay = $base('POST', "/pension-cases/{$adjacentCaseId}/service-records", [
        'entity_id' => $entity->id,
        'start_date' => '2005-01-01',
        'end_date' => '2005-01-01',
    ]);
    check(
        'task 37: fin igual al inicio responde 422',
        $individualSameDay->status() === 422 && isset($individualSameDay->json('errors')['end_date']),
        detail($individualSameDay),
    );

    // Cruza la segunda fila almacenada (1991-01-01…2000-12-31):
    // comparte 2000-12-31.
    $individualOverlap = $base('POST', "/pension-cases/{$adjacentCaseId}/service-records", [
        'entity_id' => $entity->id,
        'start_date' => '2000-12-31',
        'end_date' => '2010-12-31',
    ]);
    check(
        'task 37: período que solapa un subregistro existente responde 422',
        $individualOverlap->status() === 422 && isset($individualOverlap->json('errors')['end_date']),
        detail($individualOverlap),
    );

    $individualDisjoint = $base('POST', "/pension-cases/{$adjacentCaseId}/service-records", [
        'entity_id' => $entity->id,
        'start_date' => '2001-01-01',
        'end_date' => '2010-12-31',
    ]);
    check(
        'task 37: período disjunto admitido (día siguiente al fin almacenado)',
        $individualDisjoint->status() === 201 && ($individualDisjoint->json('data.end_date') ?? '') === '2010-12-31',
        detail($individualDisjoint),
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
    // (per_page ampliado: la fumiga crea más expedientes desde la
    // Task 37 y el expediente del promovente original debe seguir
    // dentro de la página)
    $listing = $base('GET', '/pension-cases?per_page=50&status=submitted');
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
