<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Presentation\Controllers;

use App\Modules\Organizations\Application\Contracts\EntityServiceInterface;
use App\Modules\Organizations\Presentation\Requests\EntityIndexRequest;
use App\Modules\Organizations\Presentation\Requests\StoreEntityRequest;
use App\Modules\Organizations\Presentation\Requests\UpdateEntityRequest;
use App\Modules\Organizations\Presentation\Resources\EntityResource;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * HTTP surface for entities (RF-ENT-001, RF-ENT-005).
 *
 * Thin by design (ADR-11/12): reference validation, the RN-004
 * coherence, the RN-003 acyclicity and the natural-key rules live in
 * the EntityService behind the EntityServiceInterface port; this
 * layer only translates outcomes into the RF-API-002 envelope. The
 * OA attributes keep the OpenAPI spec attached to this surface
 * (ADR-13).
 */
final class EntityController
{
    public function __construct(
        private readonly EntityServiceInterface $entities,
    ) {}

    #[OA\Get(
        path: '/api/v1/entities',
        operationId: 'entitiesIndex',
        tags: ['Estructura'],
        summary: 'Listado paginado de entidades',
        description: 'Entidades activas con referencias anidadas (RF-ENT-005): búsqueda por fragmentos de código, NIT u objeto social, y filtros por organismo, provincia, municipio y tipo. Código y NIT quedan reservados tras desactivar.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\QueryParameter(name: 'q', description: 'Fragmentos de código, NIT u objeto social', schema: new OA\Schema(type: 'string', maxLength: 120)),
            new OA\QueryParameter(name: 'organization_id', schema: new OA\Schema(type: 'integer', nullable: true)),
            new OA\QueryParameter(name: 'province_id', schema: new OA\Schema(type: 'integer', nullable: true)),
            new OA\QueryParameter(name: 'municipality_id', schema: new OA\Schema(type: 'integer', nullable: true)),
            new OA\QueryParameter(name: 'entity_type_id', schema: new OA\Schema(type: 'integer', nullable: true)),
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
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Entity')),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'total', type: 'integer', example: 30),
                                new OA\Property(property: 'last_page', type: 'integer', example: 2),
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
    public function index(EntityIndexRequest $request): JsonResponse
    {
        $paginator = $this->entities->search(
            $request->filters(),
            (int) $request->query('page', '1'),
            (int) $request->query('per_page', '15'),
        );

        return EntityResource::collection($paginator)->response();
    }

    #[OA\Get(
        path: '/api/v1/entities/tree',
        operationId: 'entitiesTree',
        tags: ['Estructura'],
        summary: 'Árbol de la jerarquía de entidades',
        description: 'Jerarquía completa como árbol anidado (RF-ENT-005), con profundidad máxima de 5 niveles: los nodos cortados al límite exponen deeper=true en lugar de ocultar su subárbol en silencio. El conteo de expedientes por oficina se incorpora con el módulo PensionCases (F3).',
        security: [['sanctumAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Árbol de jerarquía (nodos raíz en orden de id)',
                content: new OA\JsonContent(
                    required: ['data'],
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', format: 'int64'),
                                    new OA\Property(property: 'code', type: 'string'),
                                    new OA\Property(property: 'tax_id_number', type: 'string'),
                                    new OA\Property(property: 'social_purpose', type: 'string'),
                                    new OA\Property(property: 'children', type: 'array', items: new OA\Items(type: 'object')),
                                    new OA\Property(property: 'deeper', type: 'boolean', description: 'Presente solo en nodos cortados al nivel máximo'),
                                ],
                                type: 'object',
                            ),
                        ),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
        ],
    )]
    public function tree(): JsonResponse
    {
        return response()->json(['data' => $this->entities->tree()]);
    }

    #[OA\Get(
        path: '/api/v1/entities/{id}',
        operationId: 'entitiesShow',
        tags: ['Estructura'],
        summary: 'Detalle de una entidad',
        description: 'Devuelve la entidad activa con ese id (las desactivadas responden 404; su código y NIT siguen reservados).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Entidad',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Entity'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 404, description: 'Entidad inexistente o desactivada'),
        ],
    )]
    public function show(int $id): JsonResponse
    {
        $entity = $this->entities->get($id);

        abort_if($entity === null, 404, 'Entity not found.');

        return response()->json(['data' => new EntityResource($entity)]);
    }

    #[OA\Post(
        path: '/api/v1/entities',
        operationId: 'entitiesStore',
        tags: ['Estructura'],
        summary: 'Registro de una entidad',
        description: 'Alta con validación de referencias, coherencia geográfica (RN-004: el municipio pertenece a la provincia) y unicidad de código y NIT (RF-ENT-001). La jerarquía opcional (entidad superior) se mantiene acíclica (RN-003). Los directores referencian personas registradas. Toda escritura aterriza en la bitácora con valores previos.',
        security: [['sanctumAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'tax_id_number', 'organization_id', 'province_id', 'municipality_id', 'entity_type_id', 'address', 'social_purpose'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', example: 'ENT-0001', description: 'Único; reservado tras desactivar'),
                    new OA\Property(property: 'tax_id_number', type: 'string', example: '11000012345', description: 'NIT único'),
                    new OA\Property(property: 'organization_id', type: 'integer', example: 1, description: 'Organismo de pertenencia (catálogo)'),
                    new OA\Property(property: 'province_id', type: 'integer', example: 12),
                    new OA\Property(property: 'municipality_id', type: 'integer', example: 42, description: 'Debe pertenecer a la provincia (RN-004)'),
                    new OA\Property(property: 'entity_type_id', type: 'integer', example: 1, description: 'Tipo de entidad (catálogo)'),
                    new OA\Property(property: 'address', type: 'string', example: 'Calle 1 #2, Holguín'),
                    new OA\Property(property: 'phone', type: 'string', nullable: true, example: '024 461234'),
                    new OA\Property(property: 'fax', type: 'string', nullable: true),
                    new OA\Property(property: 'email', type: 'string', nullable: true, example: 'contacto@ent.gob.cu'),
                    new OA\Property(property: 'director_person_id', type: 'integer', nullable: true, description: 'Director general (persona registrada)'),
                    new OA\Property(property: 'economic_director_person_id', type: 'integer', nullable: true, description: 'Director económico (persona registrada)'),
                    new OA\Property(property: 'parent_entity_id', type: 'integer', nullable: true, description: 'Entidad superior (RN-003: sin ciclos)'),
                    new OA\Property(property: 'social_purpose', type: 'string', example: 'Servicios técnicos especializados'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Entidad registrada con autoría estampada',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Entity'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function store(StoreEntityRequest $request): JsonResponse
    {
        $entity = $this->entities->create($request->validated());

        return response()->json(['data' => new EntityResource($entity)], 201);
    }

    #[OA\Patch(
        path: '/api/v1/entities/{id}',
        operationId: 'entitiesUpdate',
        tags: ['Estructura'],
        summary: 'Edición de una entidad',
        description: 'Edición parcial con auditoría de valores previos (RF-ENT-002). El código y el NIT son inmutables (422 si intentan cambiar); las referencias, la coherencia RN-004 y la aciclicidad RN-003 se revalidan contra el estado resultante.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'organization_id', type: 'integer'),
                    new OA\Property(property: 'province_id', type: 'integer'),
                    new OA\Property(property: 'municipality_id', type: 'integer'),
                    new OA\Property(property: 'entity_type_id', type: 'integer'),
                    new OA\Property(property: 'address', type: 'string'),
                    new OA\Property(property: 'phone', type: 'string', nullable: true),
                    new OA\Property(property: 'fax', type: 'string', nullable: true),
                    new OA\Property(property: 'email', type: 'string', nullable: true),
                    new OA\Property(property: 'director_person_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'economic_director_person_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'parent_entity_id', type: 'integer', nullable: true, description: 'null desarraiga la entidad; el nuevo padre no puede cerrar un ciclo (RN-003)'),
                    new OA\Property(property: 'social_purpose', type: 'string'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Entidad actualizada (valores previos en la bitácora)',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Entity'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
            new OA\Response(response: 404, description: 'Entidad inexistente o desactivada'),
        ],
    )]
    public function update(UpdateEntityRequest $request, int $id): JsonResponse
    {
        $entity = $this->entities->update($id, $request->validated());

        abort_if($entity === null, 404, 'Entity not found.');

        return response()->json(['data' => new EntityResource($entity)]);
    }

    #[OA\Delete(
        path: '/api/v1/entities/{id}',
        operationId: 'entitiesDestroy',
        tags: ['Estructura'],
        summary: 'Desactivación de una entidad',
        description: 'Borrado lógico: la entidad sale de listados, árbol y detalle, pero su código y NIT quedan reservados y el borrado queda auditado. Se rechaza mientras tenga entidades hijas activas (la jerarquía nunca huérfana un subárbol vivo).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Entidad desactivada', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string', example: 'Entity deactivated.')],
            )),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422, description: 'Tiene entidades hijas activas'),
            new OA\Response(response: 404, description: 'Entidad inexistente o ya desactivada'),
        ],
    )]
    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->entities->delete($id);

        abort_if(! $deleted, 404, 'Entity not found.');

        return response()->json(['message' => 'Entity deactivated.']);
    }
}
