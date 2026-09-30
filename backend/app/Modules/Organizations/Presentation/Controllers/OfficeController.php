<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Presentation\Controllers;

use App\Modules\Organizations\Application\Contracts\OfficeServiceInterface;
use App\Modules\Organizations\Presentation\Requests\OfficeIndexRequest;
use App\Modules\Organizations\Presentation\Requests\StoreOfficeRequest;
use App\Modules\Organizations\Presentation\Requests\UpdateOfficeRequest;
use App\Modules\Organizations\Presentation\Resources\OfficeResource;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * HTTP surface for offices (RF-ENT-002, RF-ENT-005).
 *
 * Thin by design (ADR-11/12): reference validation, the RN-004
 * coherence and the RN-003 acyclicity live in the OfficeService
 * behind the OfficeServiceInterface port; this layer only translates
 * outcomes into the RF-API-002 envelope (ADR-13).
 */
final class OfficeController
{
    public function __construct(
        private readonly OfficeServiceInterface $offices,
    ) {}

    #[OA\Get(
        path: '/api/v1/offices',
        operationId: 'officesIndex',
        tags: ['Estructura'],
        summary: 'Listado paginado de oficinas',
        description: 'Oficinas activas con tipo, provincia y municipio (RF-ENT-002): búsqueda por fragmentos de dirección y filtros por tipo y geografía.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\QueryParameter(name: 'q', description: 'Fragmentos de la dirección', schema: new OA\Schema(type: 'string', maxLength: 120)),
            new OA\QueryParameter(name: 'office_type_id', schema: new OA\Schema(type: 'integer', nullable: true)),
            new OA\QueryParameter(name: 'province_id', schema: new OA\Schema(type: 'integer', nullable: true)),
            new OA\QueryParameter(name: 'municipality_id', schema: new OA\Schema(type: 'integer', nullable: true)),
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
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Office')),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'total', type: 'integer', example: 24),
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
    public function index(OfficeIndexRequest $request): JsonResponse
    {
        $paginator = $this->offices->search(
            $request->filters(),
            (int) $request->query('page', '1'),
            (int) $request->query('per_page', '15'),
        );

        return OfficeResource::collection($paginator)->response();
    }

    #[OA\Get(
        path: '/api/v1/offices/tree',
        operationId: 'officesTree',
        tags: ['Estructura'],
        summary: 'Árbol de la jerarquía de oficinas',
        description: 'Jerarquía completa como árbol anidado (RF-ENT-005) con profundidad máxima de 5 niveles y el corte anunciado (deeper=true). Cada nodo incluye el conteo de expedientes tramitados por la oficina (cases_count) y por su ámbito (scope_cases_count: la oficina y sus subordinadas activas, ADR-28).',
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
                                    new OA\Property(property: 'address', type: 'string'),
                                    new OA\Property(property: 'type', type: 'object', nullable: true),
                                    new OA\Property(property: 'cases_count', type: 'integer', description: 'Expedientes tramitados por la oficina (todo estado)'),
                                    new OA\Property(property: 'scope_cases_count', type: 'integer', description: 'Expedientes en su ámbito (ella y sus subordinadas activas)'),
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
        return response()->json(['data' => $this->offices->tree()]);
    }

    #[OA\Get(
        path: '/api/v1/offices/{id}',
        operationId: 'officesShow',
        tags: ['Estructura'],
        summary: 'Detalle de una oficina',
        description: 'Devuelve la oficina activa con ese id (las desactivadas responden 404) con el conteo de expedientes tramitados por la oficina y por su ámbito (RF-ENT-005, ADR-28).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Oficina',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Office'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 404, description: 'Oficina inexistente o desactivada'),
        ],
    )]
    public function show(int $id): JsonResponse
    {
        $office = $this->offices->get($id);

        abort_if($office === null, 404, 'Office not found.');

        return response()->json([
            'data' => OfficeResource::withCaseCountSummary(
                $office,
                $this->offices->caseCountSummary($office),
            ),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/offices',
        operationId: 'officesStore',
        tags: ['Estructura'],
        summary: 'Registro de una oficina',
        description: 'Alta con validación de referencias, coherencia geográfica (RN-004) y la estructura territorial (ADR-31): una sola oficina nacional, una provincial por provincia, una municipal por provincia y municipio; las provinciales dependen de la nacional (que debe existir) y las municipales de la provincial de su provincia — el parent se deriva del tipo, se puede omitir y una contradicción responde 422. Toda escritura aterriza en la bitácora.',
        security: [['sanctumAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['office_type_id', 'province_id', 'municipality_id', 'address'],
                properties: [
                    new OA\Property(property: 'office_type_id', type: 'integer', example: 2, description: 'Nacional/provincial/municipal (catálogo); la tríada territorial gobierna la unicidad y el parent'),
                    new OA\Property(property: 'province_id', type: 'integer', example: 12),
                    new OA\Property(property: 'municipality_id', type: 'integer', example: 42, description: 'Debe pertenecer a la provincia (RN-004)'),
                    new OA\Property(property: 'address', type: 'string', example: 'Calle Martí #100, Holguín'),
                    new OA\Property(property: 'parent_office_id', type: 'integer', nullable: true, description: 'Derivado del tipo: omitir o enviar el id que corresponde (provincial -> nacional, municipal -> provincial de la provincia, nacional sin parent)'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Oficina registrada con autoría estampada',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Office'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function store(StoreOfficeRequest $request): JsonResponse
    {
        $office = $this->offices->create($request->validated());

        return response()->json(['data' => new OfficeResource($office)], 201);
    }

    #[OA\Patch(
        path: '/api/v1/offices/{id}',
        operationId: 'officesUpdate',
        tags: ['Estructura'],
        summary: 'Edición de una oficina',
        description: 'Edición parcial con auditoría de valores previos. Las referencias y la coherencia RN-004 se revalidan contra el estado resultante, la unicidad por ámbito se recalcula excluyendo la propia oficina y el parent se re-deriva del tipo resultante; los cambios de tipo o territorio se rechazan mientras la oficina tenga hijas activas. Para tipos fuera de la tríada territorial rige la jerarquía opcional acíclica (RN-003).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'office_type_id', type: 'integer'),
                    new OA\Property(property: 'province_id', type: 'integer'),
                    new OA\Property(property: 'municipality_id', type: 'integer'),
                    new OA\Property(property: 'address', type: 'string'),
                    new OA\Property(property: 'parent_office_id', type: 'integer', nullable: true, description: 'En la tríada territorial se deriva del tipo (una contradicción responde 422); en tipos genéricos null desarraiga la oficina sin cerrar ciclos (RN-003)'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Oficina actualizada (valores previos en la bitácora)',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Office'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
            new OA\Response(response: 404, description: 'Oficina inexistente o desactivada'),
        ],
    )]
    public function update(UpdateOfficeRequest $request, int $id): JsonResponse
    {
        $office = $this->offices->update($id, $request->validated());

        abort_if($office === null, 404, 'Office not found.');

        return response()->json(['data' => new OfficeResource($office)]);
    }

    #[OA\Delete(
        path: '/api/v1/offices/{id}',
        operationId: 'officesDestroy',
        tags: ['Estructura'],
        summary: 'Desactivación de una oficina',
        description: 'Borrado lógico auditado. Se rechaza mientras tenga oficinas hijas activas.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Oficina desactivada', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string', example: 'Office deactivated.')],
            )),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422, description: 'Tiene oficinas hijas activas'),
            new OA\Response(response: 404, description: 'Oficina inexistente o ya desactivada'),
        ],
    )]
    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->offices->delete($id);

        abort_if(! $deleted, 404, 'Office not found.');

        return response()->json(['message' => 'Office deactivated.']);
    }
}
