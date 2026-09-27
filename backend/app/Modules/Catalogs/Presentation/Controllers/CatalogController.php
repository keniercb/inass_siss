<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Presentation\Controllers;

use App\Modules\Catalogs\Application\Contracts\CatalogServiceInterface;
use App\Modules\Catalogs\Application\Exceptions\CatalogEntryNotDeletedException;
use App\Modules\Catalogs\Application\Exceptions\CatalogHasActiveReferencesException;
use App\Modules\Catalogs\Application\Exceptions\UnknownCatalogException;
use App\Modules\Catalogs\Presentation\Requests\CatalogIndexRequest;
use App\Modules\Catalogs\Presentation\Requests\StoreCatalogRequest;
use App\Modules\Catalogs\Presentation\Requests\UpdateCatalogRequest;
use App\Modules\Catalogs\Presentation\Resources\CatalogResource;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Generic HTTP surface for the uniform catalogs (RF-CAT-001, ADR-15).
 *
 * Deliberately thin: validation arrives through the FormRequests
 * (rules driven by the CatalogRegistry), the use cases sit behind
 * the CatalogServiceInterface port and all data access lives in the
 * repository behind it. This layer only translates service outcomes
 * into the response envelope (RF-API-002) and nothing else. The OA
 * attributes keep the OpenAPI spec attached to this surface (ADR-13).
 */
final class CatalogController
{
    public function __construct(
        private readonly CatalogServiceInterface $catalogs,
    ) {}

    #[OA\Get(
        path: '/api/v1/catalogs/{type}',
        operationId: 'catalogsIndex',
        tags: ['Catalogs'],
        summary: 'Listado paginado de un catálogo',
        description: 'Lista las entradas activas de un catálogo uniforme (RF-CAT-006: paginación, búsqueda por texto y orden por columnas clave). Tipos válidos: provinces, agency-types, organizations, entity-types, office-types, legal-basis-types, scientific-categories, educational-levels, occupational-categories, pension-types, beneficiary-types, races, positions, pension-regimes, payment-types, income-concepts.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'type', description: 'Clave del catálogo', required: true, schema: new OA\Schema(type: 'string', example: 'pension-types')),
            new OA\QueryParameter(name: 'search', description: 'Filtro por nombre (y código cuando el catálogo lo tiene)', schema: new OA\Schema(type: 'string', example: 'edad')),
            new OA\QueryParameter(name: 'sort', description: 'Columna de orden', schema: new OA\Schema(type: 'string', enum: ['name', 'code', 'id'], default: 'name')),
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
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CatalogItem')),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'total', type: 'integer', example: 3),
                                new OA\Property(property: 'last_page', type: 'integer', example: 1),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(
                response: 404,
                description: 'Catálogo desconocido',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Unknown catalog.')]),
            ),
        ],
    )]
    public function index(CatalogIndexRequest $request, string $type): JsonResponse
    {
        try {
            $paginator = $this->catalogs->list(
                $type,
                $request->validated('search'),
                $request->validated('sort'),
                (string) ($request->validated('order') ?? 'asc'),
                (int) ($request->validated('page') ?? 1),
                (int) ($request->validated('per_page') ?? 15),
            );
        } catch (UnknownCatalogException) {
            abort(404, "Unknown catalog [{$type}].");
        }

        return CatalogResource::collection($paginator)->response();
    }

    #[OA\Post(
        path: '/api/v1/catalogs/{type}',
        operationId: 'catalogsStore',
        tags: ['Catalogs'],
        summary: 'Crear una entrada de catálogo',
        description: 'Crea una entrada de un catálogo uniforme (RF-CAT-001). El código es requerido cuando el catálogo lo tiene y es inmutable tras la creación. Las claves naturales duplicadas responden 422 con error por campo (RN-008).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'type', required: true, schema: new OA\Schema(type: 'string', example: 'pension-regimes')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Campos de la entrada; los opcionales dependen del catálogo',
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', example: 'GEN'),
                    new OA\Property(property: 'name', type: 'string', example: 'General'),
                    new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Régimen general de 12 meses por año'),
                    new OA\Property(property: 'months_per_year', type: 'integer', minimum: 1, maximum: 12, example: 12),
                    new OA\Property(property: 'applies_base_salary', type: 'boolean', example: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Entrada creada',
                content: new OA\JsonContent(required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CatalogItem')]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
            new OA\Response(
                response: 404,
                description: 'Catálogo desconocido',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Unknown catalog.')]),
            ),
        ],
    )]
    public function store(StoreCatalogRequest $request, string $type): JsonResponse
    {
        try {
            $model = $this->catalogs->create($type, $request->validated());
        } catch (UnknownCatalogException) {
            abort(404, "Unknown catalog [{$type}].");
        }

        return response()->json(['data' => new CatalogResource($model)], 201);
    }

    #[OA\Get(
        path: '/api/v1/catalogs/{type}/{id}',
        operationId: 'catalogsShow',
        tags: ['Catalogs'],
        summary: 'Detalle de una entrada de catálogo',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'type', required: true, schema: new OA\Schema(type: 'string', example: 'races')),
            new OA\PathParameter(name: 'id', required: true, schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Entrada solicitada',
                content: new OA\JsonContent(required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CatalogItem')]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(
                response: 404,
                description: 'Catálogo desconocido o entrada inexistente',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Not Found')]),
            ),
        ],
    )]
    public function show(string $type, int $id): JsonResponse
    {
        try {
            $model = $this->catalogs->get($type, $id);
        } catch (UnknownCatalogException) {
            abort(404, "Unknown catalog [{$type}].");
        }

        if ($model === null) {
            abort(404, 'Catalog entry not found.');
        }

        return response()->json(['data' => new CatalogResource($model)]);
    }

    #[OA\Patch(
        path: '/api/v1/catalogs/{type}/{id}',
        operationId: 'catalogsUpdate',
        tags: ['Catalogs'],
        summary: 'Editar una entrada de catálogo',
        description: 'Actualiza campos de una entrada (PATCH). El código es inmutable: enviar un código distinto responde 422 (RF-CAT-001).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'type', required: true, schema: new OA\Schema(type: 'string', example: 'pension-types')),
            new OA\PathParameter(name: 'id', required: true, schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Campos a actualizar (semántica PATCH)',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Por edad (actualizado)'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'months_per_year', type: 'integer', minimum: 1, maximum: 12),
                    new OA\Property(property: 'applies_base_salary', type: 'boolean'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Entrada actualizada',
                content: new OA\JsonContent(required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CatalogItem')]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
            new OA\Response(
                response: 404,
                description: 'Catálogo desconocido o entrada inexistente',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Not Found')]),
            ),
        ],
    )]
    public function update(UpdateCatalogRequest $request, string $type, int $id): JsonResponse
    {
        try {
            $model = $this->catalogs->update($type, $id, $request->validated());
        } catch (UnknownCatalogException) {
            abort(404, "Unknown catalog [{$type}].");
        }

        if ($model === null) {
            abort(404, 'Catalog entry not found.');
        }

        return response()->json(['data' => new CatalogResource($model)]);
    }

    #[OA\Delete(
        path: '/api/v1/catalogs/{type}/{id}',
        operationId: 'catalogsDestroy',
        tags: ['Catalogs'],
        summary: 'Desactivar una entrada de catálogo',
        description: 'Borrado lógico (RF-CAT-001): la entrada queda desactivada y excluida de los listados. Responde 409 mientras existan registros activos que la referencien.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'type', required: true, schema: new OA\Schema(type: 'string', example: 'agency-types')),
            new OA\PathParameter(name: 'id', required: true, schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Entrada desactivada',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Catalog entry deactivated.')]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(
                response: 404,
                description: 'Catálogo desconocido o entrada inexistente',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Not Found')]),
            ),
            new OA\Response(
                response: 409,
                description: 'Existen referencias activas que bloquean la desactivación',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'The catalog entry cannot be deactivated while active records reference it.')]),
            ),
        ],
    )]
    public function destroy(string $type, int $id): JsonResponse
    {
        try {
            $deactivated = $this->catalogs->deactivate($type, $id);
        } catch (UnknownCatalogException) {
            abort(404, "Unknown catalog [{$type}].");
        } catch (CatalogHasActiveReferencesException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        if (! $deactivated) {
            abort(404, 'Catalog entry not found.');
        }

        return response()->json(['message' => 'Catalog entry deactivated.']);
    }

    #[OA\Post(
        path: '/api/v1/catalogs/{type}/{id}/restore',
        operationId: 'catalogRestore',
        tags: ['Catalogs'],
        summary: 'Restaurar una entrada desactivada',
        description: 'Devuelve a la vida una entrada lógicamente desactivada (RF-AUD-004). La restauración es exclusiva del rol con permiso catalogs.manage (Administrador) y queda registrada en la bitácora con autor, fecha y valores restaurados.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'type', required: true, schema: new OA\Schema(type: 'string', example: 'races')),
            new OA\PathParameter(name: 'id', required: true, schema: new OA\Schema(type: 'integer', format: 'int64', example: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Entrada restaurada',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Catalog entry restored.')],
                ),
            ),
            new OA\Response(
                response: 403,
                description: 'Sin permiso catalogs.manage',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Forbidden.')]),
            ),
            new OA\Response(
                response: 404,
                description: 'Catálogo desconocido o entrada inexistente',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Not Found')]),
            ),
            new OA\Response(
                response: 409,
                description: 'La entrada ya está activa',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'The catalog entry is already active: only deactivated entries can be restored.')]),
            ),
        ],
    )]
    public function restore(string $type, int $id): JsonResponse
    {
        try {
            $restored = $this->catalogs->restore($type, $id);
        } catch (UnknownCatalogException) {
            abort(404, "Unknown catalog [{$type}].");
        } catch (CatalogEntryNotDeletedException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        if (! $restored) {
            abort(404, 'Catalog entry not found.');
        }

        return response()->json(['message' => 'Catalog entry restored.']);
    }
}
