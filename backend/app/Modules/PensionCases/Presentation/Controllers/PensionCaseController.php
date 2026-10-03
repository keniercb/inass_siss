<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Presentation\Controllers;

use App\Modules\PensionCases\Application\Contracts\PensionCaseServiceInterface;
use App\Modules\PensionCases\Application\Exceptions\CaseNotEditableException;
use App\Modules\PensionCases\Application\Exceptions\DuplicateIncomeConceptException;
use App\Modules\PensionCases\Application\Exceptions\DuplicateSalaryYearException;
use App\Modules\PensionCases\Application\Exceptions\OpenCaseExistsException;
use App\Modules\PensionCases\Application\Exceptions\PersonNotEligibleException;
use App\Modules\PensionCases\Domain\ServiceDeclarationForm;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\IncomeConceptRecord;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\SalaryRecord;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\ServiceRecord;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\WorkCycle;
use App\Modules\PensionCases\Presentation\Requests\PensionCaseIndexRequest;
use App\Modules\PensionCases\Presentation\Requests\StoreIncomeConceptRecordRequest;
use App\Modules\PensionCases\Presentation\Requests\StorePensionCaseRequest;
use App\Modules\PensionCases\Presentation\Requests\StoreSalaryRecordRequest;
use App\Modules\PensionCases\Presentation\Requests\StoreServiceRecordRequest;
use App\Modules\PensionCases\Presentation\Requests\StoreWorkCycleRequest;
use App\Modules\PensionCases\Presentation\Requests\UpdatePensionCaseRequest;
use App\Modules\PensionCases\Presentation\Resources\IncomeConceptRecordResource;
use App\Modules\PensionCases\Presentation\Resources\PensionCaseResource;
use App\Modules\PensionCases\Presentation\Resources\SalaryRecordResource;
use App\Modules\PensionCases\Presentation\Resources\ServiceRecordResource;
use App\Modules\PensionCases\Presentation\Resources\WorkCycleResource;
use App\Modules\Shared\Contracts\CurrentUserOfficeProviderInterface;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * HTTP surface for pension cases, Sprint 5 (RF-EXP-001..004) plus
 * the user rules 0-5 (ADR-32/ADR-33).
 *
 * Deliberately thin (ADR-11): validation arrives through the
 * FormRequests, the creation, eligibility, uniqueness and
 * editability rules sit behind the PensionCaseServiceInterface port
 * and all data access lives in the repository behind it. The
 * advisory analysis travels as a sibling `warnings` object of the
 * envelope: evidence for the specialist, never case state. Since
 * the Task 37 user correction the service periods are CLOSED and
 * DISJOINT by construction — mandatory end strictly after the
 * start (422) and no overlap between subrecords (422) — so the
 * warnings envelope carries the missing salary years alone; the
 * overlapping/open services keys left the contract.
 *
 * User rule 0 (ADR-33): store() never reads office_id from the
 * payload — the Shared office port resolves the REGISTERING USER's
 * office and the controller injects it into the attributes, so the
 * assumption is explicit at the boundary and the service keeps
 * validating it like any other reference. Since the Task 41 user
 * correction (SGP-35) the listing rides the SAME seam: index()
 * never reads office_id from the query — the port resolves the
 * AUTHENTICATED USER's office and the controller injects it into
 * the search filters, so the territorial scope is explicit at the
 * boundary and the service keeps it fail-closed.
 *
 * Conventions of the module's error surface: a deceased or
 * deactivated applicant answers 422 (PersonNotEligibleException), a
 * person already holding an open case answers 409 with that case
 * (OpenCaseExistsException), a repeated (case, year) or (case,
 * concept) answers 422 (DuplicateSalaryYearException /
 * DuplicateIncomeConceptException) and subrecord writes on a case
 * that already left `submitted` answer 409 with its current status
 * (CaseNotEditableException).
 */
final class PensionCaseController
{
    public function __construct(
        private readonly PensionCaseServiceInterface $cases,
        private readonly CurrentUserOfficeProviderInterface $registeringOffices,
    ) {}

    #[OA\Get(
        path: '/api/v1/pension-cases',
        operationId: 'pensionCasesIndex',
        tags: ['Expedientes'],
        summary: 'Listado de expedientes',
        description: 'Listado filtrable por estado, persona, número y rango de fechas de solicitud, paginado (RF-EXP-011; la búsqueda afinada con volumen llega en S6), con ALCANCE TERRITORIAL (SGP-35, corrección de usuario): solo cargan los expedientes cuya oficina coincide con la OFICINA DEL USUARIO AUTENTICADO — la oficina no viaja en la query (422 prohibido si llega) porque el servidor la deriva de la asignación del actor (ADR-33/ADR-29, el mismo patrón del alta), y un actor sin oficina recibe una página VACÍA (fail-closed, nunca el directorio sin scope). Cada fila viaja con la proyección COMPLETA del promovente (regla de usuario 3).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\QueryParameter(name: 'status', schema: new OA\Schema(type: 'string', enum: ['submitted', 'under_review', 'approved', 'rejected'])),
            new OA\QueryParameter(name: 'applicant_person_id', schema: new OA\Schema(type: 'integer')),
            new OA\QueryParameter(name: 'number', schema: new OA\Schema(type: 'string', maxLength: 20)),
            new OA\QueryParameter(name: 'requested_from', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\QueryParameter(name: 'requested_to', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\QueryParameter(name: 'page', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\QueryParameter(name: 'per_page', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resultados paginados con envelope RF-API-002',
                content: new OA\JsonContent(
                    required: ['data'],
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PensionCase')),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'total', type: 'integer', example: 42),
                                new OA\Property(property: 'last_page', type: 'integer', example: 3),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function index(PensionCaseIndexRequest $request): JsonResponse
    {
        // SGP-35 (user correction): the listing is scoped to the
        // office of the AUTHENTICATED USER — the same ADR-33 seam the
        // store rides. The wire contract already rejected a query
        // office_id (422), so the only source left is the actor's
        // assignment; an actor without an office keeps a fail-closed
        // empty page (the service owns that guard).
        $filters = $request->filters();
        $filters['office_id'] = $this->registeringOffices->currentOfficeId();

        $paginator = $this->cases->search(
            $filters,
            (int) $request->query('page', '1'),
            (int) $request->query('per_page', '15'),
        );

        return PensionCaseResource::collection($paginator)->response();
    }

    #[OA\Get(
        path: '/api/v1/pension-cases/{id}',
        operationId: 'pensionCasesShow',
        tags: ['Expedientes'],
        summary: 'Detalle de un expediente',
        description: 'Devuelve el expediente con sus subregistros (salarios, servicios, ciclos) y la proyección completa del promovente, junto al objeto warnings: años salariales interiores ausentes (RF-EXP-002). Las advertencias son evidencia para el especialista, nunca bloqueos; los períodos de servicio llegan cerrados y disjuntos por construcción (Task 37).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Expediente con subregistros y advertencias',
                content: new OA\JsonContent(
                    required: ['data'],
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/PensionCase'),
                        new OA\Property(
                            property: 'warnings',
                            type: 'object',
                            description: 'Análisis de evidencia (Task 37): solo los años interiores ausentes de la serie salarial — los períodos de servicio son cerrados y disjuntos por construcción',
                            properties: [
                                new OA\Property(property: 'missing_salary_years', type: 'array', items: new OA\Items(type: 'integer'), example: [2019, 2020]),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 404, description: 'Expediente inexistente o desactivado'),
        ],
    )]
    public function show(int $id): JsonResponse
    {
        $case = $this->cases->get($id);

        abort_if($case === null, 404, 'Case not found.');

        return (new PensionCaseResource($case))
            ->additional(['warnings' => $this->cases->warnings($case)])
            ->response();
    }

    #[OA\Post(
        path: '/api/v1/pension-cases',
        operationId: 'pensionCasesStore',
        tags: ['Expedientes'],
        summary: 'Apertura de un expediente',
        description: 'Alta del expediente (RF-EXP-001, reglas de usuario 0-5/ADR-32/33/34): el expediente ASUME la oficina del usuario que lo registra — office_id no se envía en el POST (422 si llega) — y el número se compone PPMMAACCCCC (códigos de provincia y municipio de la oficina registrante, últimos dos dígitos del año en curso y consecutivo por año/provincia/municipio rellenado con ceros, once dígitos contiguos). El proponente debe estar vivo y activo (RF-SEG-003: 422) y no puede tener otro expediente abierto (409). La serie salarial admite máximo 15 filas (regla 1); el par de Ejército Rebelde exige la fecha de alta cuando el booleano es true y la rechaza cuando es false (regla 4); los conceptos de ingreso se declaran como subregistros anidados (regla 5). Task 37: la marca internacionalista del promovente es booleana OBLIGATORIA (paralelo del par rebelde) y el par de contacto (phone, popular_council) viaja opcional; los subregistros de servicio exigen end_date OBLIGATORIA, estrictamente posterior a start_date y SIN solapamiento entre filas (422 con nada creado). Task 42: el domicilio y cobro del promovente viajan en el alta — dirección actual, provincia y municipio de residencia (coherentes, RN-04), tipo de agencia de cobro, agencia de cobro (del tipo declarado) y cuenta bancaria OBLIGATORIA CONDICIONAL a la forma de pago del tipo de agencia (exigida con tarjeta magnetica, opcional con nomina electronica: 422 sobre bank_account) — y cada concepto de ingreso declarado viaja con su porciento a aplicar (0-100, dos decimales). Los subregistros opcionales se crean en la misma transacción: todo o nada (S5.5). El techo del año salarial es el año actual+1; los pares año-expediente y concepto-expediente son únicos (422). Las advertencias viajan junto a data.',
        security: [['sanctumAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['applicant_person_id', 'employer_entity_id', 'position_id', 'occupational_category_id', 'educational_level_id', 'scientific_category_id', 'pension_type_id', 'pension_regime_id', 'rebel_army_member', 'internationalist', 'current_address', 'residence_province_id', 'residence_municipality_id', 'collection_agency_type_id', 'collection_agency_id', 'last_salary'],
                properties: [
                    new OA\Property(property: 'applicant_person_id', type: 'integer', example: 7),
                    new OA\Property(property: 'office_id', type: 'integer', nullable: true, example: null, description: 'PROHIBIDO (regla 0): el expediente asume la oficina del usuario autenticado'),
                    new OA\Property(property: 'employer_entity_id', type: 'integer', example: 3),
                    new OA\Property(property: 'position_id', type: 'integer', example: 2),
                    new OA\Property(property: 'occupational_category_id', type: 'integer', example: 1),
                    new OA\Property(property: 'educational_level_id', type: 'integer', example: 4),
                    new OA\Property(property: 'scientific_category_id', type: 'integer', example: 2),
                    new OA\Property(property: 'pension_type_id', type: 'integer', example: 1, description: 'Tipo de pensión del catálogo (regla 4)'),
                    new OA\Property(property: 'pension_regime_id', type: 'integer', example: 1, description: 'Régimen de pensión del catálogo (regla 4)'),
                    new OA\Property(property: 'rebel_army_member', type: 'boolean', example: false, description: 'Pertenece al Ejército Rebelde (regla 4)'),
                    new OA\Property(property: 'rebel_army_join_date', type: 'string', format: 'date', nullable: true, example: null, description: 'Fecha de alta en el Ejército Rebelde: obligatoria si rebel_army_member=true, rechazada si false'),
                    new OA\Property(property: 'internationalist', type: 'boolean', example: true, description: 'Internacionalista (Task 37, corrección de usuario): el promovente cumplió misión internacionalista — booleano OBLIGATORIO, paralelo de rebel_army_member; 422 si se omite'),
                    new OA\Property(property: 'filed_by_person_id', type: 'integer', format: 'int64', nullable: true, example: 12, description: 'Persona por (Task 35, corrección de usuario; columna inglesa desde Task 36): id de la persona REGISTRADA que presenta o gestiona el expediente cuando no es el propio proponente — 422 si no existe o está desactivada; la omisión persiste null; el response devuelve además la proyección completa bajo filed_by'),
                    new OA\Property(property: 'phone', type: 'string', nullable: true, maxLength: 30, example: '+53 5 555 1234', description: 'Teléfono de contacto del promovente (Task 37): texto libre opcional, 30 caracteres como techo; la omisión persiste null'),
                    new OA\Property(property: 'popular_council', type: 'string', nullable: true, maxLength: 120, example: 'Consejo Popular Playa', description: 'Consejo popular del promovente (Task 37): división territorial cubana, texto libre opcional de 120 caracteres como techo; la omisión persiste null'),
                    new OA\Property(property: 'termination_date', type: 'string', format: 'date', nullable: true, example: '2025-07-31', description: 'Fecha de desvinculación del promovente (Task 38, corrección de usuario): opcional, Y-m-d; 422 con formato inválido, la omisión persiste null'),
                    new OA\Property(property: 'current_address', type: 'string', example: 'Calle 8 #10 entre 5 y 7, Playa', description: 'Dirección actual del promovente (Task 42, corrección de usuario): OBLIGATORIA; 422 si se omite'),
                    new OA\Property(property: 'residence_province_id', type: 'integer', format: 'int64', example: 11, description: 'Provincia de residencia del promovente (Task 42): OBLIGATORIA, activa; el municipio debe pertenecerle (RN-04, 422)'),
                    new OA\Property(property: 'residence_municipality_id', type: 'integer', format: 'int64', example: 3, description: 'Municipio de residencia del promovente (Task 42): OBLIGATORIO, activo y de la provincia declarada (422)'),
                    new OA\Property(property: 'collection_agency_type_id', type: 'integer', format: 'int64', example: 1, description: 'Tipo de agencia de cobro (Task 42): OBLIGATORIO, activo; su payment_form (tarjeta magnetica|nomina electronica) decide la exigencia de la cuenta bancaria'),
                    new OA\Property(property: 'collection_agency_id', type: 'integer', format: 'int64', example: 7, description: 'Agencia de cobro (Task 42): OBLIGATORIA, activa y del tipo declarado (422)'),
                    new OA\Property(property: 'bank_account', type: 'string', nullable: true, maxLength: 34, example: '01234567890123456789012345678', description: 'Cuenta bancaria del cobro (Task 42): OBLIGATORIA CONDICIONAL — exigida (422 sobre bank_account) cuando la forma de pago del tipo de agencia de cobro es tarjeta magnetica, opcional con nomina electronica (la omisión persiste null)'),
                    new OA\Property(property: 'last_salary', type: 'string', example: '5000.00', description: 'Último salario, decimal exacto no negativo (RN-005)'),
                    new OA\Property(property: 'requested_at', type: 'string', format: 'date', nullable: true, example: '2026-09-30', description: 'Opcional; por defecto hoy; nunca futura'),
                    new OA\Property(
                        property: 'salary_records',
                        type: 'array',
                        maxItems: 15,
                        description: 'Serie salarial inicial, máximo 15 filas (regla 1, todo o nada)',
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'year', type: 'integer', example: 2024),
                                new OA\Property(property: 'earned_salary', type: 'string', example: '4800.00'),
                            ],
                            type: 'object',
                        ),
                    ),
                    new OA\Property(
                        property: 'service_records',
                        type: 'array',
                        description: 'Historial laboral inicial (todo o nada; Task 37: end_date obligatoria, estrictamente posterior a start_date y sin solapamiento entre filas — 422)',
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'entity_id', type: 'integer', example: 3),
                                new OA\Property(property: 'start_date', type: 'string', format: 'date', example: '2000-01-01'),
                                new OA\Property(property: 'end_date', type: 'string', format: 'date', example: '2005-12-31', description: 'Fecha de fin del vínculo: OBLIGATORIA y estrictamente posterior a start_date'),
                                new OA\Property(property: 'is_appendix', type: 'boolean', example: false),
                                new OA\Property(property: 'declaration_form', type: 'string', enum: ['Documental', 'Testifical'], example: 'Documental', description: 'Forma de declaración del vínculo; Documental por omisión'),
                            ],
                            type: 'object',
                        ),
                    ),
                    new OA\Property(
                        property: 'work_cycles',
                        type: 'array',
                        description: 'Ciclos de trabajo iniciales (todo o nada)',
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'planned_days', type: 'integer', example: 300),
                                new OA\Property(property: 'actual_days', type: 'integer', example: 280),
                                new OA\Property(property: 'cycles_count', type: 'integer', example: 1),
                            ],
                            type: 'object',
                        ),
                    ),
                    new OA\Property(
                        property: 'income_concept_records',
                        type: 'array',
                        description: 'Conceptos de ingreso declarados (regla 5, todo o nada): un valor por concepto',
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'income_concept_id', type: 'integer', example: 3),
                                new OA\Property(property: 'amount', type: 'string', example: '150.00'),
                                new OA\Property(property: 'applied_percent', type: 'string', example: '100.00', description: 'Porciento a aplicar (Task 42): OBLIGATORIO por fila — decimal exacto 0-100 con dos decimales (422 si se omite, fuera de rango o con tercera decimal)'),
                            ],
                            type: 'object',
                        ),
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Expediente creado (con subregistros y advertencias)',
                content: new OA\JsonContent(
                    required: ['data'],
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/PensionCase'),
                        new OA\Property(property: 'warnings', type: 'object', description: 'Análisis de evidencia (Task 37): años interiores ausentes de la serie salarial'),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
            new OA\Response(
                response: 409,
                description: 'La persona ya tiene un expediente abierto (devuelve el expediente) o el expediente ya no es editable (devuelve estado actual)',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'case', ref: '#/components/schemas/PensionCase', nullable: true),
                        new OA\Property(property: 'case_id', type: 'integer', nullable: true),
                        new OA\Property(property: 'status', type: 'string', nullable: true),
                    ],
                ),
            ),
        ],
    )]
    public function store(StorePensionCaseRequest $request): JsonResponse
    {
        // User rule 0 (ADR-33): the case assumes the REGISTERING
        // USER's office — never a client-supplied value. The wire
        // contract already rejected a payload office_id (422), so
        // the only source left is the actor's assignment.
        $officeId = $this->registeringOffices->currentOfficeId();

        if ($officeId === null) {
            throw ValidationException::withMessages([
                'office_id' => 'The authenticated user has no office assigned; a case assumes the registering user\'s office.',
            ]);
        }

        $attributes = $request->validated();
        $attributes['office_id'] = $officeId;

        try {
            $case = $this->cases->create($attributes);
        } catch (OpenCaseExistsException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'case' => new PensionCaseResource($exception->openCase),
            ], 409);
        } catch (PersonNotEligibleException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['applicant_person_id' => [$exception->getMessage()]],
            ], 422);
        }

        return (new PensionCaseResource($case))
            ->additional(['warnings' => $this->cases->warnings($case)])
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Put(
        path: '/api/v1/pension-cases/{id}',
        operationId: 'pensionCasesUpdate',
        tags: ['Expedientes'],
        summary: 'Edición del expediente (promovente inmutable)',
        description: 'PUT de edición del expediente (SGP-34, corrección de usuario, RF-EXP-001): edita los campos del expediente propio — el vínculo laboral y la clasificación de la pensión (entidad, cargo, ambos pares de categorías, tipo y régimen), el último salario y la fecha de solicitud — mientras el PROMOVENTE de la pensión queda INMUTABLE: todo campo de la esfera de la persona (applicant_person_id, filed_by_person_id, el par de Ejército Rebelde, el internacionalista, el par de contacto y la fecha de desvinculación) responde 422 prohibido en vez de derivar silenciosamente al promovente que el registro ya conoce. Los campos de ciclo de vida siguen la misma suerte: office_id respeta la regla 0 del alta (el expediente asume la oficina del usuario que registra) y number/status solo se mueven por sus propios canales (la secuencia del alta, la máquina de transiciones de S6). Semántica PATCH: cada campo es opcional, solo las claves declaradas cambian y la omisión de un campo nunca arranca su valor almacenado. Los probes semánticos espejan el alta (entidad y catálogos activos: 422; fecha de solicitud no futura: 422). La edición solo corre mientras el expediente está en submitted (409 fuera, con el estado actual). Task 42: el grupo de DOMICILIO y COBRO del promovente — dirección actual, provincia y municipio de residencia, tipo de agencia de cobro, agencia de cobro y cuenta bancaria — SÍ es editable (decisión explícita del usuario: pueden modificarse) con probes espejo del alta y la exigencia condicional de la cuenta re-evaluada contra el estado RESULTANTE (cambiar el tipo de agencia de cobro a uno con forma de pago tarjeta magnetica exige la cuenta si acabó en null). Las advertencias de la serie salarial viajan junto a data.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'employer_entity_id', type: 'integer', example: 3, description: 'Entidad empleadora del expediente (editable, activa)'),
                    new OA\Property(property: 'position_id', type: 'integer', example: 2, description: 'Cargo (editable)'),
                    new OA\Property(property: 'occupational_category_id', type: 'integer', example: 1, description: 'Categoría ocupacional (editable)'),
                    new OA\Property(property: 'educational_level_id', type: 'integer', example: 4, description: 'Nivel de escolaridad (editable)'),
                    new OA\Property(property: 'scientific_category_id', type: 'integer', example: 2, description: 'Categoría científica (editable)'),
                    new OA\Property(property: 'pension_type_id', type: 'integer', example: 1, description: 'Tipo de pensión del catálogo (editable)'),
                    new OA\Property(property: 'pension_regime_id', type: 'integer', example: 1, description: 'Régimen de pensión del catálogo (editable)'),
                    new OA\Property(property: 'last_salary', type: 'string', example: '6200.00', description: 'Último salario, decimal exacto no negativo (RN-005, editable)'),
                    new OA\Property(property: 'requested_at', type: 'string', format: 'date', example: '2026-09-30', description: 'Fecha de solicitud (editable, nunca futura)'),
                    new OA\Property(property: 'current_address', type: 'string', example: 'Calle 23 #100, Vedado', description: 'Dirección actual del promovente (Task 42, EDITABLE): semántica PATCH'),
                    new OA\Property(property: 'residence_province_id', type: 'integer', format: 'int64', example: 11, description: 'Provincia de residencia (Task 42, EDITABLE): coherente con el municipio resultante (RN-04)'),
                    new OA\Property(property: 'residence_municipality_id', type: 'integer', format: 'int64', example: 3, description: 'Municipio de residencia (Task 42, EDITABLE): de la provincia resultante'),
                    new OA\Property(property: 'collection_agency_type_id', type: 'integer', format: 'int64', example: 1, description: 'Tipo de agencia de cobro (Task 42, EDITABLE): su payment_form decide la exigencia de la cuenta resultante'),
                    new OA\Property(property: 'collection_agency_id', type: 'integer', format: 'int64', example: 7, description: 'Agencia de cobro (Task 42, EDITABLE): activa y del tipo resultante'),
                    new OA\Property(property: 'bank_account', type: 'string', nullable: true, maxLength: 34, example: '01234567890123456789012345678', description: 'Cuenta bancaria del cobro (Task 42, EDITABLE): null explícito la LIMPIA; 422 si el estado resultante exige cuenta (tarjeta magnetica) y acabó null'),
                    new OA\Property(property: 'applicant_person_id', type: 'integer', example: 7, description: 'PROHIBIDO (SGP-34): el promovente de la pensión es no modificable — 422 si se envía'),
                    new OA\Property(property: 'filed_by_person_id', type: 'integer', format: 'int64', nullable: true, example: 12, description: 'PROHIBIDO (SGP-34): esfera de persona, no modificable por este endpoint — 422 si se envía'),
                    new OA\Property(property: 'rebel_army_member', type: 'boolean', example: false, description: 'PROHIBIDO (SGP-34): esfera de persona, no modificable — 422 si se envía'),
                    new OA\Property(property: 'rebel_army_join_date', type: 'string', format: 'date', nullable: true, example: null, description: 'PROHIBIDO (SGP-34): esfera de persona, no modificable — 422 si se envía'),
                    new OA\Property(property: 'internationalist', type: 'boolean', example: true, description: 'PROHIBIDO (SGP-34): esfera de persona, no modificable — 422 si se envía'),
                    new OA\Property(property: 'phone', type: 'string', nullable: true, maxLength: 30, example: '+53 5 555 1234', description: 'PROHIBIDO (SGP-34): esfera de persona, no modificable — 422 si se envía'),
                    new OA\Property(property: 'popular_council', type: 'string', nullable: true, maxLength: 120, example: 'Consejo Popular Playa', description: 'PROHIBIDO (SGP-34): esfera de persona, no modificable — 422 si se envía'),
                    new OA\Property(property: 'termination_date', type: 'string', format: 'date', nullable: true, example: '2025-07-31', description: 'PROHIBIDO (SGP-34): esfera de persona, no modificable — 422 si se envía'),
                    new OA\Property(property: 'office_id', type: 'integer', nullable: true, example: null, description: 'PROHIBIDO (regla 0): el expediente asume la oficina del usuario autenticado — 422 si se envía'),
                    new OA\Property(property: 'number', type: 'string', example: '11032600099', description: 'PROHIBIDO: el número se asigna en el alta y no se modifica — 422 si se envía'),
                    new OA\Property(property: 'status', type: 'string', example: 'submitted', description: 'PROHIBIDO: el estado solo se mueve por su propio canal de transiciones — 422 si se envía'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Expediente editado con las advertencias de la serie salarial',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/PensionCase'),
                        new OA\Property(property: 'warnings', type: 'object'),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
            new OA\Response(response: 404, description: 'Expediente inexistente (o ya eliminado)'),
            new OA\Response(response: 409, description: 'Expediente ya no editable (devuelve estado actual)'),
        ],
    )]
    public function update(UpdatePensionCaseRequest $request, int $id): JsonResponse
    {
        // SGP-34 (user correction): case edition with an IMMUTABLE
        // promovente — the FormRequest already rejected every
        // person-sphere key (422), so only case proper fields ride
        // the payload. The 409 of the editable state rides the same
        // caseNotEditable shape of the subrecord writes.
        try {
            $case = $this->cases->update($id, $request->validated());
        } catch (CaseNotEditableException $exception) {
            return $this->caseNotEditable($exception);
        }

        abort_if($case === null, 404, 'Case not found.');

        return (new PensionCaseResource($case))
            ->additional(['warnings' => $this->cases->warnings($case)])
            ->response();
    }

    #[OA\Delete(
        path: '/api/v1/pension-cases/{id}',
        operationId: 'pensionCasesDestroy',
        tags: ['Expedientes'],
        summary: 'Eliminación lógica del expediente',
        description: 'DELETE del expediente (SGP-34, corrección de usuario, RF-EXP-001): eliminación LÓGICA (soft delete) disponible SOLO mientras el expediente está en submitted (estado de solicitud) — fuera de submitted responde 409 con el estado actual. La fila sobrevive con su deleted_at: la evidencia y la pista de auditoría siguen respondiendo (RN-001) con los valores previos (ADR-19), mientras el detalle y el listado públicos dejan de verlo (404). Los subregistros no se tocan: la historia queda física. La reservación de expediente-abierto-por-persona se LIBERA (la columna generada open_case_key pasa a NULL en las filas eliminadas) para que el operador pueda re-capturar al mismo solicitante tras eliminar un registro equivocado.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Expediente eliminado lógicamente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Case deleted.'),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 404, description: 'Expediente inexistente (o ya eliminado)'),
            new OA\Response(response: 409, description: 'Expediente fuera de submitted (devuelve estado actual)'),
        ],
    )]
    public function destroy(int $id): JsonResponse
    {
        // SGP-34 (user correction): the soft delete of a submitted
        // case — only the case row dies (logically); the subrecords
        // and the audit trail survive by construction.
        try {
            $deleted = $this->cases->delete($id);
        } catch (CaseNotEditableException $exception) {
            return $this->caseNotEditable($exception);
        }

        abort_if($deleted === null, 404, 'Case not found.');

        return response()->json(['message' => 'Case deleted.']);
    }

    #[OA\Post(
        path: '/api/v1/pension-cases/{id}/salary-records',
        operationId: 'pensionCasesAddSalaryRecord',
        tags: ['Expedientes'],
        summary: 'Alta de un registro de salario',
        description: 'Añade un año de salario devengado (RF-EXP-002) mientras el expediente está en submitted. El par año-expediente es único (422 semántico) y el año respeta 1950…año actual+1. La respuesta lleva el objeto warnings con los años interiores ausentes de la serie resultante.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['year', 'earned_salary'],
                properties: [
                    new OA\Property(property: 'year', type: 'integer', example: 2024),
                    new OA\Property(property: 'earned_salary', type: 'string', example: '4800.00'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Registro añadido con advertencias de la serie',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/SalaryRecord'),
                        new OA\Property(property: 'warnings', type: 'object'),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
            new OA\Response(response: 404, description: 'Expediente inexistente'),
            new OA\Response(response: 409, description: 'Expediente ya no editable (devuelve estado actual)'),
        ],
    )]
    public function addSalaryRecord(StoreSalaryRecordRequest $request, int $id): JsonResponse
    {
        return $this->addSubrecord(
            fn (): ?SalaryRecord => $this->cases->addSalaryRecord($id, (int) $request->validated()['year'], (string) $request->validated()['earned_salary']),
            $id,
        );
    }

    #[OA\Delete(
        path: '/api/v1/pension-cases/{id}/salary-records/{record}',
        operationId: 'pensionCasesRemoveSalaryRecord',
        tags: ['Expedientes'],
        summary: 'Baja de un registro de salario',
        description: 'Elimina un año de la serie salarial (RF-EXP-002, altas/bajas de S5.4) mientras el expediente está en submitted. La bitácora conserva los valores previos (ADR-19).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
            new OA\PathParameter(name: 'record', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Registro eliminado (con advertencias actualizadas)', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Salary record removed.'),
                    new OA\Property(property: 'warnings', type: 'object'),
                ],
            )),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 404, description: 'Expediente o registro inexistente'),
            new OA\Response(response: 409, description: 'Expediente ya no editable (devuelve estado actual)'),
        ],
    )]
    public function removeSalaryRecord(int $id, int $record): JsonResponse
    {
        return $this->removeSubrecord(
            fn (): ?bool => $this->cases->removeSalaryRecord($id, $record),
            $id,
            'Salary record removed.',
        );
    }

    #[OA\Post(
        path: '/api/v1/pension-cases/{id}/service-records',
        operationId: 'pensionCasesAddServiceRecord',
        tags: ['Expedientes'],
        summary: 'Alta de un registro de servicio',
        description: 'Añade un vínculo laboral (RF-EXP-003) mientras el expediente está en submitted. Task 37 (corrección de usuario): end_date es OBLIGATORIA, estrictamente posterior a start_date (422) y el período no puede solapar NINGÚN subregistro existente del expediente (422 sobre end_date nombrando los registros cruzados) — los vínculos abiertos y los solapamientos advertidos de Sprint 5 ya no existen.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['entity_id', 'start_date', 'end_date'],
                properties: [
                    new OA\Property(property: 'entity_id', type: 'integer', example: 3),
                    new OA\Property(property: 'start_date', type: 'string', format: 'date', example: '2000-01-01'),
                    new OA\Property(property: 'end_date', type: 'string', format: 'date', example: '2005-12-31', description: 'OBLIGATORIA (Task 37) y estrictamente posterior a start_date; sin solapamiento con los subregistros existentes'),
                    new OA\Property(property: 'is_appendix', type: 'boolean', example: false, description: 'Coletilla'),
                    new OA\Property(property: 'declaration_form', type: 'string', enum: ['Documental', 'Testifical'], example: 'Documental', description: 'Forma de declaración del vínculo; por defecto Documental'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Registro añadido con advertencias de la serie salarial',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/ServiceRecord'),
                        new OA\Property(property: 'warnings', type: 'object'),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
            new OA\Response(response: 404, description: 'Expediente inexistente'),
            new OA\Response(response: 409, description: 'Expediente ya no editable (devuelve estado actual)'),
        ],
    )]
    public function addServiceRecord(StoreServiceRecordRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();

        return $this->addSubrecord(
            fn (): ?ServiceRecord => $this->cases->addServiceRecord($id, [
                'entity_id' => (int) $validated['entity_id'],
                'start_date' => (string) $validated['start_date'],
                // Task 37: mandatory end — validated() guarantees the
                // key because the FormRequest requires it.
                'end_date' => (string) $validated['end_date'],
                'is_appendix' => (bool) ($validated['is_appendix'] ?? false),
                'declaration_form' => (string) ($validated['declaration_form'] ?? ServiceDeclarationForm::Documental->value),
            ]),
            $id,
        );
    }

    #[OA\Delete(
        path: '/api/v1/pension-cases/{id}/service-records/{record}',
        operationId: 'pensionCasesRemoveServiceRecord',
        tags: ['Expedientes'],
        summary: 'Baja de un registro de servicio',
        description: 'Elimina un vínculo laboral (S5.4) mientras el expediente está en submitted. La bitácora conserva los valores previos (ADR-19).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
            new OA\PathParameter(name: 'record', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Registro eliminado (con advertencias actualizadas)', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Service record removed.'),
                    new OA\Property(property: 'warnings', type: 'object'),
                ],
            )),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 404, description: 'Expediente o registro inexistente'),
            new OA\Response(response: 409, description: 'Expediente ya no editable (devuelve estado actual)'),
        ],
    )]
    public function removeServiceRecord(int $id, int $record): JsonResponse
    {
        return $this->removeSubrecord(
            fn (): ?bool => $this->cases->removeServiceRecord($id, $record),
            $id,
            'Service record removed.',
        );
    }

    #[OA\Post(
        path: '/api/v1/pension-cases/{id}/work-cycles',
        operationId: 'pensionCasesAddWorkCycle',
        tags: ['Expedientes'],
        summary: 'Alta de un ciclo de trabajo',
        description: 'Añade un ciclo de trabajo (RF-EXP-004: días plan, días reales y cantidad, enteros no negativos) mientras el expediente está en submitted.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['planned_days', 'actual_days', 'cycles_count'],
                properties: [
                    new OA\Property(property: 'planned_days', type: 'integer', example: 300),
                    new OA\Property(property: 'actual_days', type: 'integer', example: 280),
                    new OA\Property(property: 'cycles_count', type: 'integer', example: 1),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Ciclo añadido',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/WorkCycle'),
                        new OA\Property(property: 'warnings', type: 'object'),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
            new OA\Response(response: 404, description: 'Expediente inexistente'),
            new OA\Response(response: 409, description: 'Expediente ya no editable (devuelve estado actual)'),
        ],
    )]
    public function addWorkCycle(StoreWorkCycleRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();

        return $this->addSubrecord(
            fn (): ?WorkCycle => $this->cases->addWorkCycle($id, [
                'planned_days' => (int) $validated['planned_days'],
                'actual_days' => (int) $validated['actual_days'],
                'cycles_count' => (int) $validated['cycles_count'],
            ]),
            $id,
        );
    }

    #[OA\Delete(
        path: '/api/v1/pension-cases/{id}/work-cycles/{record}',
        operationId: 'pensionCasesRemoveWorkCycle',
        tags: ['Expedientes'],
        summary: 'Baja de un ciclo de trabajo',
        description: 'Elimina un ciclo de trabajo (S5.4) mientras el expediente está en submitted. La bitácora conserva los valores previos (ADR-19).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
            new OA\PathParameter(name: 'record', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Ciclo eliminado (con advertencias actualizadas)', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Work cycle removed.'),
                    new OA\Property(property: 'warnings', type: 'object'),
                ],
            )),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 404, description: 'Expediente o registro inexistente'),
            new OA\Response(response: 409, description: 'Expediente ya no editable (devuelve estado actual)'),
        ],
    )]
    public function removeWorkCycle(int $id, int $record): JsonResponse
    {
        return $this->removeSubrecord(
            fn (): ?bool => $this->cases->removeWorkCycle($id, $record),
            $id,
            'Work cycle removed.',
        );
    }

    #[OA\Post(
        path: '/api/v1/pension-cases/{id}/income-concept-records',
        operationId: 'pensionCasesAddIncomeConceptRecord',
        tags: ['Expedientes'],
        summary: 'Alta de un concepto de ingreso',
        description: 'Declara el valor de un concepto de ingreso del expediente (regla de usuario 5) mientras el expediente está en submitted. El par concepto-expediente es único (422 semántico), el importe es decimal exacto no negativo (RN-005) y — desde la Task 42 (corrección de usuario) — el porciento a aplicar es OBLIGATORIO: decimal exacto en el rango 0-100 con dos decimales (422 si se omite, fuera de rango o con tercera decimal).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['income_concept_id', 'amount', 'applied_percent'],
                properties: [
                    new OA\Property(property: 'income_concept_id', type: 'integer', example: 3, description: 'Concepto del catálogo de conceptos de ingreso'),
                    new OA\Property(property: 'amount', type: 'string', example: '150.00', description: 'Importe exacto con dos decimales (RN-005)'),
                    new OA\Property(property: 'applied_percent', type: 'string', example: '50.25', description: 'Porciento a aplicar (Task 42, corrección de usuario): OBLIGATORIO — decimal exacto 0-100 con dos decimales'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Concepto declarado',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/IncomeConceptRecord'),
                        new OA\Property(property: 'warnings', type: 'object'),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
            new OA\Response(response: 404, description: 'Expediente inexistente'),
            new OA\Response(response: 409, description: 'Expediente ya no editable (devuelve estado actual)'),
        ],
    )]
    public function addIncomeConceptRecord(StoreIncomeConceptRecordRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();

        return $this->addSubrecord(
            fn (): ?IncomeConceptRecord => $this->cases->addIncomeConceptRecord(
                $id,
                (int) $validated['income_concept_id'],
                (string) $validated['amount'],
                (string) $validated['applied_percent'],
            ),
            $id,
        );
    }

    #[OA\Delete(
        path: '/api/v1/pension-cases/{id}/income-concept-records/{record}',
        operationId: 'pensionCasesRemoveIncomeConceptRecord',
        tags: ['Expedientes'],
        summary: 'Baja de un concepto de ingreso',
        description: 'Elimina el valor declarado de un concepto de ingreso (regla de usuario 5) mientras el expediente está en submitted. La bitácora conserva los valores previos (ADR-19).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
            new OA\PathParameter(name: 'record', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Concepto eliminado (con advertencias actualizadas)', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Income concept record removed.'),
                    new OA\Property(property: 'warnings', type: 'object'),
                ],
            )),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 404, description: 'Expediente o registro inexistente'),
            new OA\Response(response: 409, description: 'Expediente ya no editable (devuelve estado actual)'),
        ],
    )]
    public function removeIncomeConceptRecord(int $id, int $record): JsonResponse
    {
        return $this->removeSubrecord(
            fn (): ?bool => $this->cases->removeIncomeConceptRecord($id, $record),
            $id,
            'Income concept record removed.',
        );
    }

    /**
     * Uniform 201 for subrecord highs: the row plus the refreshed
     * warnings of the case. Missing case (null) answers 404; the
     * not-editable conflict answers 409 with the current status.
     *
     * @param  Closure(): (SalaryRecord|ServiceRecord|WorkCycle|IncomeConceptRecord|null)  $operation
     */
    private function addSubrecord(Closure $operation, int $caseId): JsonResponse
    {
        try {
            $record = $operation();
        } catch (CaseNotEditableException $exception) {
            return $this->caseNotEditable($exception);
        } catch (DuplicateSalaryYearException $exception) {
            // RF-EXP-002 semantic probe: the pair (case, year) is
            // unique — 422 with the field-level shape, not a driver
            // error (RN-008 convention).
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['year' => [$exception->getMessage()]],
            ], 422);
        } catch (DuplicateIncomeConceptException $exception) {
            // User rule 5 semantic probe: the pair (case, concept)
            // is unique — same 422 convention.
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['income_concept_id' => [$exception->getMessage()]],
            ], 422);
        }

        abort_if($record === null, 404, 'Case not found.');

        $resource = match (true) {
            $record instanceof SalaryRecord => new SalaryRecordResource($record),
            $record instanceof ServiceRecord => new ServiceRecordResource($record),
            $record instanceof IncomeConceptRecord => new IncomeConceptRecordResource($record),
            default => new WorkCycleResource($record),
        };

        $case = $this->cases->get($caseId);
        $warnings = $case !== null ? $this->cases->warnings($case) : [];

        return $resource
            ->additional(['warnings' => $warnings])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Uniform 200 for subrecord removals: message plus the refreshed
     * warnings. Null (case missing) and false (row missing) both
     * answer 404 — the distinction belongs to the log, not the wire.
     *
     * @param  Closure(): (bool|null)  $operation
     */
    private function removeSubrecord(Closure $operation, int $caseId, string $message): JsonResponse
    {
        try {
            $removed = $operation();
        } catch (CaseNotEditableException $exception) {
            return $this->caseNotEditable($exception);
        }

        abort_if($removed === null || $removed === false, 404, 'Case or record not found.');

        $case = $this->cases->get($caseId);
        $warnings = $case !== null ? $this->cases->warnings($case) : [];

        return response()->json([
            'message' => $message,
            'warnings' => $warnings,
        ]);
    }

    /**
     * 409 body of the editable-state conflict: the message plus the
     * case id and its current status, so the operator knows which
     * transition is needed instead of editing frozen evidence.
     */
    private function caseNotEditable(CaseNotEditableException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'case_id' => $exception->case->id,
            'status' => $exception->currentStatus->value,
        ], 409);
    }
}
