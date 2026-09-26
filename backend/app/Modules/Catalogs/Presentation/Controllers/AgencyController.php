<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Presentation\Controllers;

use App\Modules\Catalogs\Application\Contracts\AgencyServiceInterface;
use App\Modules\Catalogs\Presentation\Requests\AgencyIndexRequest;
use App\Modules\Catalogs\Presentation\Requests\StoreAgencyRequest;
use App\Modules\Catalogs\Presentation\Requests\UpdateAgencyRequest;
use App\Modules\Catalogs\Presentation\Resources\AgencyResource;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * HTTP surface for bank agencies (RF-CAT-003).
 *
 * Thin by design (ADR-11/12): reference validation, the RN-04
 * coherence rule and uniqueness live in the AgencyService behind the
 * AgencyServiceInterface port; this layer only translates outcomes
 * into the RF-API-002 envelope.
 */
final class AgencyController
{
    public function __construct(
        private readonly AgencyServiceInterface $agencies,
    ) {}

    #[OA\Get(
        path: '/api/v1/agencies',
        operationId: 'agenciesIndex',
        tags: ['Catalogs'],
        summary: 'Listado paginado de agencias bancarias',
        description: 'Agencias activas con provincia, municipio y tipo (RF-CAT-006: filtros por provincia, municipio y tipo, búsqueda por nombre o código).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\QueryParameter(name: 'province_id', schema: new OA\Schema(type: 'integer', nullable: true)),
            new OA\QueryParameter(name: 'municipality_id', schema: new OA\Schema(type: 'integer', nullable: true)),
            new OA\QueryParameter(name: 'agency_type_id', schema: new OA\Schema(type: 'integer', nullable: true)),
            new OA\QueryParameter(name: 'search', schema: new OA\Schema(type: 'string')),
            new OA\QueryParameter(name: 'sort', schema: new OA\Schema(type: 'string', enum: ['name', 'code', 'id'], default: 'name')),
            new OA\QueryParameter(name: 'order', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'asc')),
            new OA\QueryParameter(name: 'page', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\QueryParameter(name: 'per_page', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Listado paginado con envelope RF-API-002',
                content: new OA\JsonContent(
                    required: ['data'],
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Agency')),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'total', type: 'integer', example: 25),
                                new OA\Property(property: 'last_page', type: 'integer', example: 2),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
        ],
    )]
    public function index(AgencyIndexRequest $request): JsonResponse
    {
        $paginator = $this->agencies->list(
            self::nullableInt($request->validated('province_id')),
            self::nullableInt($request->validated('municipality_id')),
            self::nullableInt($request->validated('agency_type_id')),
            $request->validated('search'),
            $request->validated('sort'),
            (string) ($request->validated('order') ?? 'asc'),
            (int) ($request->validated('page') ?? 1),
            (int) ($request->validated('per_page') ?? 15),
        );

        return AgencyResource::collection($paginator)->response();
    }

    /**
     * Query strings arrive as strings; the Application contracts
     * stay strictly typed, so the HTTP edge adapts.
     *
     * @param  mixed  $value
     */
    private static function nullableInt($value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    #[OA\Get(
        path: '/api/v1/agencies/{id}',
        operationId: 'agenciesShow',
        tags: ['Catalogs'],
        summary: 'Detalle de una agencia bancaria',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', required: true, schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Agencia solicitada',
                content: new OA\JsonContent(required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Agency')]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(
                response: 404,
                description: 'Agencia inexistente',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Agency not found.')]),
            ),
        ],
    )]
    public function show(int $id): JsonResponse
    {
        $agency = $this->agencies->get($id);

        if ($agency === null) {
            abort(404, 'Agency not found.');
        }

        return response()->json(['data' => new AgencyResource($agency->load(['province', 'municipality', 'agencyType']))]);
    }

    #[OA\Post(
        path: '/api/v1/agencies',
        operationId: 'agenciesStore',
        tags: ['Catalogs'],
        summary: 'Crear una agencia bancaria',
        description: 'Crea una agencia (RF-CAT-003). El municipio debe pertenecer a la provincia declarada (RN-04); la incoherencia responde 422 sobre municipality_id.',
        security: [['sanctumAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'name', 'province_id', 'municipality_id', 'agency_type_id'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', example: 'BPA1207'),
                    new OA\Property(property: 'name', type: 'string', example: 'Agencia Holguín BPA'),
                    new OA\Property(property: 'province_id', type: 'integer', example: 12),
                    new OA\Property(property: 'municipality_id', type: 'integer', example: 42),
                    new OA\Property(property: 'agency_type_id', type: 'integer', example: 1),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Agencia creada',
                content: new OA\JsonContent(required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Agency')]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function store(StoreAgencyRequest $request): JsonResponse
    {
        $agency = $this->agencies->create($request->validated());

        return response()->json(
            ['data' => new AgencyResource($agency->load(['province', 'municipality', 'agencyType']))],
            201,
        );
    }

    #[OA\Patch(
        path: '/api/v1/agencies/{id}',
        operationId: 'agenciesUpdate',
        tags: ['Catalogs'],
        summary: 'Editar una agencia bancaria',
        description: 'Actualiza campos de una agencia (PATCH). El código es inmutable tras la creación; los cambios de ubicación revalidan RN-04.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', required: true, schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'province_id', type: 'integer'),
                    new OA\Property(property: 'municipality_id', type: 'integer'),
                    new OA\Property(property: 'agency_type_id', type: 'integer'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Agencia actualizada',
                content: new OA\JsonContent(required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Agency')]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
            new OA\Response(
                response: 404,
                description: 'Agencia inexistente',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Agency not found.')]),
            ),
        ],
    )]
    public function update(UpdateAgencyRequest $request, int $id): JsonResponse
    {
        $agency = $this->agencies->update($id, $request->validated());

        if ($agency === null) {
            abort(404, 'Agency not found.');
        }

        return response()->json(['data' => new AgencyResource($agency->load(['province', 'municipality', 'agencyType']))]);
    }

    #[OA\Delete(
        path: '/api/v1/agencies/{id}',
        operationId: 'agenciesDestroy',
        tags: ['Catalogs'],
        summary: 'Desactivar una agencia bancaria',
        description: 'Borrado lógico de la agencia (RF-CAT-001).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', required: true, schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Agencia desactivada',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Agency deactivated.')]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(
                response: 404,
                description: 'Agencia inexistente',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Agency not found.')]),
            ),
        ],
    )]
    public function destroy(int $id): JsonResponse
    {
        $deactivated = $this->agencies->deactivate($id);

        if (! $deactivated) {
            abort(404, 'Agency not found.');
        }

        return response()->json(['message' => 'Agency deactivated.']);
    }
}
