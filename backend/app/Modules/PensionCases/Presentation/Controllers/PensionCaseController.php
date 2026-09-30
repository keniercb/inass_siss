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
 * advisory analysis (missing salary years, overlapping/open
 * services, RF-EXP-002/003) travels as a sibling `warnings` object
 * of the envelope: evidence for the specialist, never case state.
 *
 * User rule 0 (ADR-33): store() never reads office_id from the
 * payload — the Shared office port resolves the REGISTERING USER's
 * office and the controller injects it into the attributes, so the
 * assumption is explicit at the boundary and the service keeps
 * validating it like any other reference.
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
        description: 'Listado filtrable por estado, oficina, persona, número y rango de fechas de solicitud, paginado (RF-EXP-011; la búsqueda afinada con volumen llega en S6). Cada fila viaja con la proyección COMPLETA del promovente (regla de usuario 3).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\QueryParameter(name: 'status', schema: new OA\Schema(type: 'string', enum: ['submitted', 'under_review', 'approved', 'rejected'])),
            new OA\QueryParameter(name: 'office_id', schema: new OA\Schema(type: 'integer')),
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
        $paginator = $this->cases->search(
            $request->filters(),
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
        description: 'Devuelve el expediente con sus subregistros (salarios, servicios, ciclos) y el resumen del proponente, junto al objeto warnings: años salariales interiores ausentes (RF-EXP-002), pares de servicios solapados y vínculos sin cerrar (RF-EXP-003). Las advertencias son evidencia para el especialista, nunca bloqueos.',
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
                            properties: [
                                new OA\Property(property: 'missing_salary_years', type: 'array', items: new OA\Items(type: 'integer'), example: [2019, 2020]),
                                new OA\Property(property: 'overlapping_services', type: 'array', items: new OA\Items(type: 'array', items: new OA\Items(type: 'integer')), example: [[1, 2]]),
                                new OA\Property(property: 'open_services', type: 'array', items: new OA\Items(type: 'integer'), example: [3]),
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
        description: 'Alta del expediente (RF-EXP-001, reglas de usuario 0-5/ADR-32/33/34): el expediente ASUME la oficina del usuario que lo registra — office_id no se envía en el POST (422 si llega) — y el número se compone PPMMAACCCCC (códigos de provincia y municipio de la oficina registrante, últimos dos dígitos del año en curso y consecutivo por año/provincia/municipio rellenado con ceros, once dígitos contiguos). El proponente debe estar vivo y activo (RF-SEG-003: 422) y no puede tener otro expediente abierto (409). La serie salarial admite máximo 15 filas (regla 1); el par de Ejército Rebelde exige la fecha de alta cuando el booleano es true y la rechaza cuando es false (regla 4); los conceptos de ingreso se declaran como subregistros anidados (regla 5). Los subregistros opcionales se crean en la misma transacción: todo o nada (S5.5). El techo del año salarial es el año actual+1; los pares año-expediente y concepto-expediente son únicos (422). Las advertencias viajan junto a data.',
        security: [['sanctumAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['applicant_person_id', 'employer_entity_id', 'position_id', 'occupational_category_id', 'educational_level_id', 'scientific_category_id', 'pension_type_id', 'pension_regime_id', 'rebel_army_member', 'last_salary'],
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
                        description: 'Historial laboral inicial (todo o nada)',
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'entity_id', type: 'integer', example: 3),
                                new OA\Property(property: 'start_date', type: 'string', format: 'date', example: '2000-01-01'),
                                new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true, example: null),
                                new OA\Property(property: 'is_appendix', type: 'boolean', example: false),
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
                        new OA\Property(property: 'warnings', type: 'object', description: 'Análisis de evidencia (huecos, solapamientos, vínculos abiertos)'),
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
        description: 'Añade un vínculo laboral (RF-EXP-003) mientras el expediente está en submitted. end_date null = vínculo vigente y debe ser ≥ start_date (422). Los solapamientos y vínculos abiertos NO bloquean: viajan en warnings (id de pares solapados, ids de vínculos abiertos).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['entity_id', 'start_date'],
                properties: [
                    new OA\Property(property: 'entity_id', type: 'integer', example: 3),
                    new OA\Property(property: 'start_date', type: 'string', format: 'date', example: '2000-01-01'),
                    new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true, example: null, description: 'null = vínculo vigente'),
                    new OA\Property(property: 'is_appendix', type: 'boolean', example: false, description: 'Coletilla'),
                    new OA\Property(property: 'forma_declaracion', type: 'string', enum: ['Documental', 'Testifical'], example: 'Documental', description: 'Forma de declaración del vínculo; por defecto Documental'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Registro añadido con advertencias de solapamiento',
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
                'end_date' => $validated['end_date'] ?? null,
                'is_appendix' => (bool) ($validated['is_appendix'] ?? false),
                'forma_declaracion' => (string) ($validated['forma_declaracion'] ?? ServiceDeclarationForm::Documental->value),
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
        description: 'Declara el valor de un concepto de ingreso del expediente (regla de usuario 5) mientras el expediente está en submitted. El par concepto-expediente es único (422 semántico) y el importe es decimal exacto no negativo (RN-005).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['income_concept_id', 'amount'],
                properties: [
                    new OA\Property(property: 'income_concept_id', type: 'integer', example: 3, description: 'Concepto del catálogo de conceptos de ingreso'),
                    new OA\Property(property: 'amount', type: 'string', example: '150.00', description: 'Importe exacto con dos decimales (RN-005)'),
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
