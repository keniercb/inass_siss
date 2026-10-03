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
 * Task 38 (SGP-32, corrección de usuario): el expediente lleva la
 * fecha de desvinculación del promovente (termination_date, fecha
 * OPCIONAL — 201/detalle/listado la devuelven, 422 con formato
 * inválido, la omisión persiste NULL); el régimen de jubilación
 * lleva un sector entero OPCIONAL devuelto por TODOS los endpoints
 * del catálogo (201/detalle/listado/PATCH; PATCH sin sector no lo
 * toca); el tipo de pensión lleva persona fallecida (deceased_person,
 * booleano con default false devuelto por TODOS los endpoints); y el
 * GET del listado de entidades devuelve los DATOS del director
 * general y el económico como proyecciones completas de Persona (null
 * cuando la entidad no los declara, igual que el detalle).
 *
 * Task 41 (SGP-35, corrección de usuario): el listado de expedientes
 * está ALCANCE TERRITORIAL — solo cargan los expedientes cuya
 * oficina coincide con la oficina del usuario autenticado (la
 * oficina NO viaja en la query: 422 prohibido si llega; el actor sin
 * oficina recibe una página VACÍA, fail-closed; reasignar al actor a
 * otra oficina mueve el scope de punta a punta).
 *
 * Task 42 (SGP-36, corrección de usuario): el catálogo tipos de pago
 * queda ELIMINADO (payment-types responde 404; la forma de pago vive
 * en el tipo de agencia con el enum minúsculas unificadas
 * tarjeta magnetica|nomina electronica, DEFAULT tarjeta magnetica); el
 * expediente lleva el DOMICILIO y COBRO del promovente (dirección
 * actual, provincia y municipio de residencia coherentes, tipo de
 * agencia y agencia de cobro del tipo declarado, y cuenta bancaria
 * OBLIGATORIA CONDICIONADA a la forma de pago del tipo — exigida con
 * tarjeta magnetica, opcional con nomina electronica —, grupo editable
 * por PUT); y cada concepto de ingreso declarado viaja con su PORCIENTO
 * A APLICAR (0-100, dos decimales, obligatorio).
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

    // ---- Task 42: punto de cobro del promovente ----
    // (los tipos de agencia se crean por el endpoint del catálogo para
    // ejercer la superficie nueva: la forma de pago explícita, la
    // omisión que cae en el DEFAULT y el valor desconocido 422)
    $payrollType = $base('POST', '/catalogs/agency-types', [
        'code' => 'NE',
        'name' => 'Agencia de nómina',
        'payment_form' => 'nomina electronica',
    ]);
    check(
        'task 42: tipo de agencia con nomina electronica creado',
        $payrollType->status() === 201
            && ($payrollType->json('data.payment_form') ?? '') === 'nomina electronica',
        detail($payrollType),
    );

    $magneticType = $base('POST', '/catalogs/agency-types', [
        'code' => 'TM',
        'name' => 'Agencia de tarjeta',
    ]);
    check(
        'task 42: omisión de la forma de pago cae en el DEFAULT tarjeta magnetica',
        $magneticType->status() === 201
            && ($magneticType->json('data.payment_form') ?? '') === 'tarjeta magnetica',
        detail($magneticType),
    );

    $unknownForm = $base('POST', '/catalogs/agency-types', [
        'code' => 'XX',
        'name' => 'Desconocida',
        'payment_form' => 'cheque',
    ]);
    check(
        'task 42: forma de pago desconocida responde 422',
        $unknownForm->status() === 422,
        detail($unknownForm),
    );

    $payrollTypeId = (int) ($payrollType->json('data.id') ?? 0);
    $magneticTypeId = (int) ($magneticType->json('data.id') ?? 0);

    $payrollAgency = $base('POST', '/agencies', [
        'code' => 'FUMNE'.random_int(1000, 9999),
        'name' => 'Agencia BPA nómina',
        'province_id' => $habana->id,
        'municipality_id' => $municipality->id,
        'agency_type_id' => $payrollTypeId,
    ]);
    $payrollAgencyId = (int) ($payrollAgency->json('data.id') ?? 0);
    check('task 42: agencia de nómina creada', $payrollAgency->status() === 201 && $payrollAgencyId > 0, detail($payrollAgency));

    $magneticAgency = $base('POST', '/agencies', [
        'code' => 'FUMTM'.random_int(1000, 9999),
        'name' => 'Agencia BPA tarjeta',
        'province_id' => $habana->id,
        'municipality_id' => $municipality->id,
        'agency_type_id' => $magneticTypeId,
    ]);
    $magneticAgencyId = (int) ($magneticAgency->json('data.id') ?? 0);
    check('task 42: agencia de tarjeta creada', $magneticAgency->status() === 201 && $magneticAgencyId > 0, detail($magneticAgency));

    $paymentTypesGone = $base('GET', '/catalogs/payment-types');
    check('task 42: payment-types ya no es catálogo (404)', $paymentTypesGone->status() === 404, detail($paymentTypesGone));

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
        // Task 42: domicilio y cobro del promovente — el tipo de nómina
        // deja la cuenta bancaria OPCIONAL.
        'current_address' => 'Calle de la fumiga #3',
        'residence_province_id' => $habana->id,
        'residence_municipality_id' => $municipality->id,
        'collection_agency_type_id' => $payrollTypeId,
        'collection_agency_id' => $payrollAgencyId,
    ];

    // ---- Regla 0: el actor SIN oficina no puede registrar ----
    $noOffice = $base('POST', '/pension-cases', $payload);
    check('regla 0: actor sin oficina responde 422', $noOffice->status() === 422, detail($noOffice));

    // ---- SGP-35: el actor SIN oficina tampoco ve expediente alguno ----
    // (fail-closed: corridas previas dejan expedientes de SUS oficinas
    // en la BD, y el listado sin scope NO puede responderlos)
    $noScope = $base('GET', '/pension-cases');
    check('SGP-35: actor sin oficina recibe listado VACÍO', $noScope->status() === 200
        && count($noScope->json('data') ?? []) === 0, detail($noScope));

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

    // ---- Task 42: domicilio y cobro del promovente ----
    // (el tipo de nómina dejó la cuenta OPCIONAL en el payload base;
    // el tipo de tarjeta la EXIGE: la demanda es condicional)
    $groupApplicant = Person::factory()->create();
    $missingAccount = $base('POST', '/pension-cases', array_merge($payload, [
        'applicant_person_id' => $groupApplicant->id,
        'collection_agency_type_id' => $magneticTypeId,
        'collection_agency_id' => $magneticAgencyId,
    ]));
    check(
        'task 42: tarjeta magnetica exige la cuenta bancaria (422 sobre bank_account)',
        $missingAccount->status() === 422 && is_array($missingAccount->json('errors.bank_account')),
        detail($missingAccount),
    );

    $withAccount = $base('POST', '/pension-cases', array_merge($payload, [
        'applicant_person_id' => $groupApplicant->id,
        'collection_agency_type_id' => $magneticTypeId,
        'collection_agency_id' => $magneticAgencyId,
        'bank_account' => '01234567890123456789012345678',
    ]));
    $withAccountId = (int) ($withAccount->json('data.id') ?? 0);
    check(
        'task 42: con cuenta y tarjeta magnetica el alta es 201 y devuelve el grupo con sus proyecciones',
        $withAccount->status() === 201
            && ($withAccount->json('data.current_address') ?? '') === 'Calle de la fumiga #3'
            && (int) ($withAccount->json('data.collection_agency_type_id') ?? 0) === $magneticTypeId
            && ($withAccount->json('data.bank_account') ?? '') === '01234567890123456789012345678'
            && ($withAccount->json('data.collection_agency_type.payment_form') ?? '') === 'tarjeta magnetica'
            && ($base('GET', "/pension-cases/{$withAccountId}")->json('data.residence_municipality.code') ?? '') === $municipality->code
            && ($base('GET', "/pension-cases/{$withAccountId}")->json('data.collection_agency.code') ?? '') !== '',
        detail($withAccount),
    );

    $foreignMunicipality = Municipality::query()
        ->where('province_id', '!=', $habana->id)
        ->whereNotNull('province_id')
        ->firstOrFail();
    $incoherent = $base('POST', '/pension-cases', array_merge($payload, [
        'applicant_person_id' => Person::factory()->create()->id,
        'residence_municipality_id' => $foreignMunicipality->id,
    ]));
    check(
        'task 42: municipio de residencia ajeno a la provincia responde 422',
        $incoherent->status() === 422 && is_array($incoherent->json('errors.residence_municipality_id')),
        detail($incoherent),
    );

    $wrongTypeAgency = $base('POST', '/pension-cases', array_merge($payload, [
        'applicant_person_id' => Person::factory()->create()->id,
        'collection_agency_type_id' => $magneticTypeId,
        'collection_agency_id' => $payrollAgencyId,
    ]));
    check(
        'task 42: agencia de otro tipo responde 422',
        $wrongTypeAgency->status() === 422 && is_array($wrongTypeAgency->json('errors.collection_agency_id')),
        detail($wrongTypeAgency),
    );

    // ---- Task 38: fecha de desvinculación del promovente ----
    $terminationApplicant = Person::factory()->create();
    $withTermination = $base('POST', '/pension-cases', array_merge($payload, [
        'applicant_person_id' => $terminationApplicant->id,
        'termination_date' => '2025-07-31',
    ]));
    $withTerminationId = (int) ($withTermination->json('data.id') ?? 0);
    check(
        'task 38: fecha de desvinculación recibida y devuelta (201, detalle y listado)',
        $withTermination->status() === 201
            && ($withTermination->json('data.termination_date') ?? '') === '2025-07-31'
            && ($base('GET', "/pension-cases/{$withTerminationId}")->json('data.termination_date') ?? '') === '2025-07-31'
            && ($base('GET', '/pension-cases?applicant_person_id='.$terminationApplicant->id)->json('data.0.termination_date') ?? '') === '2025-07-31',
        detail($withTermination),
    );

    $noTerminationApplicant = Person::factory()->create();
    $noTermination = $base('POST', '/pension-cases', array_merge($payload, [
        'applicant_person_id' => $noTerminationApplicant->id,
    ]));
    $noTerminationData = (array) ($noTermination->json('data') ?? []);
    check(
        'task 38: fecha de desvinculación omitida persiste null',
        $noTermination->status() === 201
            && array_key_exists('termination_date', $noTerminationData)
            && $noTerminationData['termination_date'] === null,
        detail($noTermination),
    );

    $malformedTerminationApplicant = Person::factory()->create();
    $malformedTermination = $base('POST', '/pension-cases', array_merge($payload, [
        'applicant_person_id' => $malformedTerminationApplicant->id,
        'termination_date' => '31-07-2025',
    ]));
    check(
        'task 38: fecha de desvinculación con formato inválido responde 422',
        $malformedTermination->status() === 422 && isset($malformedTermination->json('errors')['termination_date']),
        detail($malformedTermination),
    );

    // ---- Task 38: sector del régimen de jubilación (todos los
    //      endpoints del catálogo) ----
    $regimeWithSector = $base('POST', '/catalogs/pension-regimes', [
        'code' => 'FUMSEC',
        'name' => 'Régimen de fumiga sectorial',
        'months_per_year' => 12,
        'sector' => 2,
    ]);
    $regimeId = (int) ($regimeWithSector->json('data.id') ?? 0);
    check(
        'task 38: sector del régimen recibido y devuelto (201, detalle y listado)',
        $regimeWithSector->status() === 201
            && $regimeWithSector->json('data.sector') === 2
            && $regimeId > 0
            && ($base('GET', "/catalogs/pension-regimes/{$regimeId}")->json('data.sector') ?? null) === 2
            && ($base('GET', '/catalogs/pension-regimes?search=FUMSEC')->json('data.0.sector') ?? null) === 2,
        detail($regimeWithSector),
    );

    $sectorPatch = $base('PATCH', "/catalogs/pension-regimes/{$regimeId}", ['sector' => 1]);
    check(
        'task 38: PATCH del sector del régimen devuelto en la respuesta',
        $sectorPatch->status() === 200 && $sectorPatch->json('data.sector') === 1,
        detail($sectorPatch),
    );

    $sectorUntouched = $base('PATCH', "/catalogs/pension-regimes/{$regimeId}", ['description' => 'Ajuste de fumiga']);
    check(
        'task 38: PATCH sin sector deja el valor intacto',
        $sectorUntouched->status() === 200 && $sectorUntouched->json('data.sector') === 1,
        detail($sectorUntouched),
    );

    $regimeNoSector = $base('POST', '/catalogs/pension-regimes', [
        'code' => 'FUMSIN',
        'name' => 'Régimen de fumiga sin sector',
        'months_per_year' => 12,
    ]);
    $regimeNoSectorData = (array) ($regimeNoSector->json('data') ?? []);
    check(
        'task 38: sector del régimen omitido persiste null',
        $regimeNoSector->status() === 201
            && array_key_exists('sector', $regimeNoSectorData)
            && $regimeNoSectorData['sector'] === null,
        detail($regimeNoSector),
    );

    $regimeBadSector = $base('POST', '/catalogs/pension-regimes', [
        'code' => 'FUMMAL',
        'name' => 'Régimen de fumiga inválido',
        'months_per_year' => 12,
        'sector' => 'dos',
    ]);
    check(
        'task 38: sector no entero responde 422',
        $regimeBadSector->status() === 422 && isset($regimeBadSector->json('errors')['sector']),
        detail($regimeBadSector),
    );

    // ---- Task 38: persona fallecida del tipo de pensión (default
    //      false, todos los endpoints del catálogo) ----
    $typePlain = $base('POST', '/catalogs/pension-types', [
        'code' => 'FUMEDAD',
        'name' => 'Tipo de fumiga por edad',
    ]);
    check(
        'task 38: persona fallecida omitida del tipo de pensión responde false (default)',
        $typePlain->status() === 201 && $typePlain->json('data.deceased_person') === false,
        detail($typePlain),
    );

    $typeDeceased = $base('POST', '/catalogs/pension-types', [
        'code' => 'FUMSOB',
        'name' => 'Tipo de fumiga por sobrevivencia',
        'deceased_person' => true,
    ]);
    $typeDeceasedId = (int) ($typeDeceased->json('data.id') ?? 0);
    check(
        'task 38: persona fallecida del tipo de pensión recibida y devuelta (201, detalle y listado)',
        $typeDeceased->status() === 201
            && $typeDeceased->json('data.deceased_person') === true
            && $typeDeceasedId > 0
            && ($base('GET', "/catalogs/pension-types/{$typeDeceasedId}")->json('data.deceased_person') ?? null) === true
            && ($base('GET', '/catalogs/pension-types?search=FUMSOB')->json('data.0.deceased_person') ?? null) === true,
        detail($typeDeceased),
    );

    $typePatch = $base('PATCH', "/catalogs/pension-types/{$typeDeceasedId}", ['deceased_person' => false]);
    check(
        'task 38: PATCH de persona fallecida devuelto en la respuesta',
        $typePatch->status() === 200 && $typePatch->json('data.deceased_person') === false,
        detail($typePatch),
    );

    $typeBadFlag = $base('POST', '/catalogs/pension-types', [
        'code' => 'FUMMALFAL',
        'name' => 'Tipo de fumiga inválido',
        'deceased_person' => 'yes',
    ]);
    check(
        'task 38: persona fallecida no booleana responde 422',
        $typeBadFlag->status() === 422 && isset($typeBadFlag->json('errors')['deceased_person']),
        detail($typeBadFlag),
    );

    // ---- Task 38 (FIX): el listado de entidades devuelve los datos
    //      del director general y el económico ----
    $smokeDirector = Person::factory()->create();
    $smokeEconomic = Person::factory()->create();
    $directorEntity = Entity::query()->create([
        'code' => 'FUM-DIR',
        'name' => 'Entidad de fumiga con directores',
        'tax_id_number' => '99000011111',
        'organization_id' => $entity->organization_id,
        'province_id' => $entity->province_id,
        'municipality_id' => $entity->municipality_id,
        'entity_type_id' => $entity->entity_type_id,
        'address' => 'Calle de la fumiga #38',
        'social_purpose' => 'Fumiga de directores',
        'director_person_id' => $smokeDirector->id,
        'economic_director_person_id' => $smokeEconomic->id,
    ]);
    $entityListing = $base('GET', '/entities?q=FUM-DIR');
    $entityRow = $entityListing->json('data.0') ?? [];
    check(
        'task 38 fix: el listado de entidades devuelve los datos del director general y el económico',
        $entityListing->status() === 200
            && ($entityRow['director']['id'] ?? 0) === $smokeDirector->id
            && ($entityRow['director']['identity_number'] ?? '') === $smokeDirector->identity_number
            && ($entityRow['director']['first_name'] ?? '') === $smokeDirector->first_name
            && ($entityRow['economic_director']['id'] ?? 0) === $smokeEconomic->id
            && ($entityRow['economic_director']['identity_number'] ?? '') === $smokeEconomic->identity_number,
        detail($entityListing),
    );

    $entityDetail = $base('GET', "/entities/{$directorEntity->id}");
    check(
        'task 38 fix: el detalle de la entidad comparte la proyección de directores',
        $entityDetail->status() === 200
            && ($entityDetail->json('data.director.id') ?? 0) === $smokeDirector->id
            && ($entityDetail->json('data.economic_director.id') ?? 0) === $smokeEconomic->id,
        detail($entityDetail),
    );

    $bareEntityListing = $base('GET', '/entities?q='.$entity->code);
    $bareRow = collect($bareEntityListing->json('data') ?? [])->firstWhere('code', $entity->code);
    check(
        'task 38 fix: entidades sin directores devuelven null, nunca un recurso roto',
        $bareEntityListing->status() === 200
            && $bareRow !== null
            && array_key_exists('director', $bareRow)
            && $bareRow['director'] === null
            && array_key_exists('economic_director', $bareRow)
            && $bareRow['economic_director'] === null,
        detail($bareEntityListing),
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
        'applied_percent' => '100',
    ]);
    check(
        'regla 5: concepto de ingreso declarado con su porciento a aplicar',
        $conceptRow->status() === 201
            && $conceptRow->json('data.amount') === '150.00'
            && $conceptRow->json('data.applied_percent') === '100.00',
        detail($conceptRow),
    );

    $duplicated = $base('POST', "/pension-cases/{$rebelCaseId}/income-concept-records", [
        'income_concept_id' => $concept->id,
        'amount' => '200.00',
        'applied_percent' => '100',
    ]);
    check('regla 5: concepto duplicado responde 422', $duplicated->status() === 422, detail($duplicated));

    // Task 42: el porciento es OBLIGATORIO — sin él, 422 (se usa el
    // segundo concepto del seeder para no chocar con el duplicado).
    $secondConcept = IncomeConcept::query()->whereKeyNot($concept->id)->orderBy('id')->first()
        ?? IncomeConcept::query()->orderByDesc('id')->first();
    $noPercent = $base('POST', "/pension-cases/{$rebelCaseId}/income-concept-records", [
        'income_concept_id' => $secondConcept->id,
        'amount' => '150.00',
    ]);
    check(
        'task 42: concepto sin porciento responde 422',
        $noPercent->status() === 422,
        detail($noPercent),
    );

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

    // ---- SGP-35: la oficina NO viaja en la query ----
    $scopedQuery = $base('GET', '/pension-cases?office_id='.$municipalId);
    check('SGP-35: office_id en la query responde 422', $scopedQuery->status() === 422
        && is_array($scopedQuery->json('errors.office_id')), detail($scopedQuery));

    // ---- SGP-35: reasignar al actor MUEVE el scope de punta a punta ----
    $toProvincial = $base('PATCH', "/users/{$adminId}", ['office_id' => $provincialId]);
    check('SGP-35: actor reasignado a la provincial (200)', $toProvincial->status() === 200, detail($toProvincial));

    $provincialListing = $base('GET', '/pension-cases?per_page=50');
    check('SGP-35: el scope provincial NO carga los expedientes municipales', $provincialListing->status() === 200
        && count($provincialListing->json('data') ?? []) === 0, detail($provincialListing));

    $backToMunicipal = $base('PATCH', "/users/{$adminId}", ['office_id' => $municipalId]);
    check('SGP-35: actor devuelto a la municipal (200)', $backToMunicipal->status() === 200, detail($backToMunicipal));

    $municipalListing = $base('GET', '/pension-cases?per_page=50');
    $municipalRows = $municipalListing->json('data') ?? [];
    $scopedAgain = count($municipalRows) > 0
        && collect($municipalRows)->firstWhere('applicant.id', $applicant->id) !== null
        && collect($municipalRows)->every(fn (array $r): bool => (int) ($r['office_id'] ?? 0) === $municipalId);
    check('SGP-35: el scope municipal vuelve a cargar SUS expedientes (y solo los suyos)', $scopedAgain, detail($municipalListing));

    // ---- Regla 0 (higiene): restaurar al admin sin oficina ----
    $restore = $base('PATCH', "/users/{$adminId}", ['office_id' => null]);
    check('higiene: admin restaurado sin oficina', $restore->status() === 200, detail($restore));

    // ---- SGP-35 (higiene): sin oficina, el listado vuelve a VACÍO ----
    $officelessListing = $base('GET', '/pension-cases');
    check('SGP-35: higiene — actor sin oficina, listado VACÍO de nuevo', $officelessListing->status() === 200
        && count($officelessListing->json('data') ?? []) === 0, detail($officelessListing));
} finally {
    proc_terminate($server);
    proc_close($server);
}

echo $failures === 0 ? "\nFumiga de expedientes: TODO OK\n" : "\nFumiga de expedientes: {$failures} fallo(s)\n";
exit($failures === 0 ? 0 : 1);
