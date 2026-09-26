<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Presentation\Controllers;

use App\Modules\Catalogs\Application\Contracts\MunicipalityServiceInterface;
use App\Modules\Catalogs\Application\Exceptions\CatalogHasActiveReferencesException;
use App\Modules\Catalogs\Presentation\Requests\MunicipalityIndexRequest;
use App\Modules\Catalogs\Presentation\Requests\StoreMunicipalityRequest;
use App\Modules\Catalogs\Presentation\Requests\UpdateMunicipalityRequest;
use App\Modules\Catalogs\Presentation\Resources\MunicipalityResource;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * HTTP surface for the municipalities catalog (RF-CAT-002).
 *
 * Thin by design (ADR-11/12): the composite natural key rules and
 * the deactivation guards live in the MunicipalityService behind the
 * MunicipalityServiceInterface port; this layer only translates
 * outcomes into the RF-API-002 envelope.
 */
final class MunicipalityController
{
    public function __construct(
        private readonly MunicipalityServiceInterface $municipalities,
    ) {}

    #[OA\Get(
        path: '/api/v1/municipalities',
        operationId: 'municipalitiesIndex',
        tags: ['Catalogs'],
        summary: 'Listado paginado de municipios',
        description: 'Municipios activos con su provincia (RF-CAT-002). Filtrable por provincia y buscable por nombre o código (RF-CAT-006).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\QueryParameter(name: 'province_id', description: 'Filtra por provincia', schema: new OA\Schema(type: 'integer', nullable: true)),
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
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Municipality')),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'total', type: 'integer', example: 168),
                                new OA\Property(property: 'last_page', type: 'integer', example: 12),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
        ],
    )]
    public function index(MunicipalityIndexRequest $request): JsonResponse
    {
        $paginator = $this->municipalities->list(
            self::nullableInt($request->validated('province_id')),
            $request->validated('search'),
            $request->validated('sort'),
            (string) ($request->validated('order') ?? 'asc'),
            (int) ($request->validated('page') ?? 1),
            (int) ($request->validated('per_page') ?? 15),
        );

        return MunicipalityResource::collection($paginator)->response();
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
        path: '/api/v1/municipalities/{id}',
        operationId: 'municipalitiesShow',
        tags: ['Catalogs'],
        summary: 'Detalle de un municipio',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', required: true, schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Municipio solicitado',
                content: new OA\JsonContent(required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Municipality')]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(
                response: 404,
                description: 'Municipio inexistente',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Municipality not found.')]),
            ),
        ],
    )]
    public function show(int $id): JsonResponse
    {
        $municipality = $this->municipalities->get($id);

        if ($municipality === null) {
            abort(404, 'Municipality not found.');
        }

        return response()->json(['data' => new MunicipalityResource($municipality->load('province'))]);
    }

    #[OA\Post(
        path: '/api/v1/municipalities',
        operationId: 'municipalitiesStore',
        tags: ['Catalogs'],
        summary: 'Crear un municipio',
        description: 'Crea un municipio (RF-CAT-002). El par código-provincia es único; el municipio especial Isla de la Juventud se crea con province_id null.',
        security: [['sanctumAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'name'],
                properties: [
                    new OA\Property(property: 'province_id', type: 'integer', nullable: true, example: 12),
                    new OA\Property(property: 'code', type: 'string', example: '99'),
                    new OA\Property(property: 'name', type: 'string', example: 'Nueva Paz'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Municipio creado',
                content: new OA\JsonContent(required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Municipality')]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function store(StoreMunicipalityRequest $request): JsonResponse
    {
        $municipality = $this->municipalities->create($request->validated());

        return response()->json(['data' => new MunicipalityResource($municipality->load('province'))], 201);
    }

    #[OA\Patch(
        path: '/api/v1/municipalities/{id}',
        operationId: 'municipalitiesUpdate',
        tags: ['Catalogs'],
        summary: 'Editar un municipio',
        description: 'Actualiza campos de un municipio (PATCH). El código es inmutable tras la creación.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', required: true, schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'province_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'name', type: 'string', example: 'Nueva Paz (renamed)'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Municipio actualizado',
                content: new OA\JsonContent(required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Municipality')]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
            new OA\Response(
                response: 404,
                description: 'Municipio inexistente',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Municipality not found.')]),
            ),
        ],
    )]
    public function update(UpdateMunicipalityRequest $request, int $id): JsonResponse
    {
        $municipality = $this->municipalities->update($id, $request->validated());

        if ($municipality === null) {
            abort(404, 'Municipality not found.');
        }

        return response()->json(['data' => new MunicipalityResource($municipality->load('province'))]);
    }

    #[OA\Delete(
        path: '/api/v1/municipalities/{id}',
        operationId: 'municipalitiesDestroy',
        tags: ['Catalogs'],
        summary: 'Desactivar un municipio',
        description: 'Borrado lógico del municipio (RF-CAT-001). Responde 409 mientras existan agencias activas que lo referencien.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', required: true, schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Municipio desactivado',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Municipality deactivated.')]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(
                response: 404,
                description: 'Municipio inexistente',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Municipality not found.')]),
            ),
            new OA\Response(
                response: 409,
                description: 'Existen agencias activas que referencian el municipio',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'The catalog entry cannot be deactivated while active records reference it: agencies.')]),
            ),
        ],
    )]
    public function destroy(int $id): JsonResponse
    {
        try {
            $deactivated = $this->municipalities->deactivate($id);
        } catch (CatalogHasActiveReferencesException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        if (! $deactivated) {
            abort(404, 'Municipality not found.');
        }

        return response()->json(['message' => 'Municipality deactivated.']);
    }
}
