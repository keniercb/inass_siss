<?php

declare(strict_types=1);

namespace App\Modules\LegalBasis\Presentation\Controllers;

use App\Modules\LegalBasis\Application\Contracts\LegalBasisServiceInterface;
use App\Modules\LegalBasis\Presentation\Requests\LegalBasisIndexRequest;
use App\Modules\LegalBasis\Presentation\Requests\StoreLegalBasisRequest;
use App\Modules\LegalBasis\Presentation\Requests\UpdateLegalBasisRequest;
use App\Modules\LegalBasis\Presentation\Resources\LegalBasisResource;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * HTTP surface for the legal corpus (RF-LEG-002..004).
 *
 * Thin by design (ADR-11/12): the tern uniqueness, the RN-006
 * ordering and the derived validity live in the LegalBasisService
 * behind the LegalBasisServiceInterface port. The status of every
 * basis is derived at read time (Domain LegalBasisStatus over the
 * Shared Clock), and the status=effective filter is the selector of
 * vigentes that the expediente approval will consume (RF-LEG-003;
 * the "forcing a derogated basis with warning" rule lands with
 * PensionCases in F3, which owns the approval transition).
 */
final class LegalBasisController
{
    public function __construct(
        private readonly LegalBasisServiceInterface $bases,
    ) {}

    #[OA\Get(
        path: '/api/v1/legal-bases',
        operationId: 'legalBasesIndex',
        tags: ['Base legal'],
        summary: 'Consulta documental del corpus legal',
        description: 'Bases legales activas con tipo y organismo emisor (RF-LEG-004): búsqueda por año, tipo, organismo emisor y texto del número o la referencia; el filtro status=effective alimenta el selector de vigentes de la aprobación de expedientes (RF-LEG-003).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\QueryParameter(name: 'q', description: 'Fragmentos del número o la referencia documental', schema: new OA\Schema(type: 'string', maxLength: 120)),
            new OA\QueryParameter(name: 'legal_basis_type_id', schema: new OA\Schema(type: 'integer', nullable: true)),
            new OA\QueryParameter(name: 'organization_id', schema: new OA\Schema(type: 'integer', nullable: true)),
            new OA\QueryParameter(name: 'year', schema: new OA\Schema(type: 'integer', minimum: 1800, maximum: 2200, nullable: true)),
            new OA\QueryParameter(name: 'status', schema: new OA\Schema(type: 'string', enum: ['effective', 'derogated', 'future'], nullable: true)),
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
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/LegalBasis')),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'total', type: 'integer', example: 60),
                                new OA\Property(property: 'last_page', type: 'integer', example: 4),
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
    public function index(LegalBasisIndexRequest $request): JsonResponse
    {
        $paginator = $this->bases->search(
            $request->filters(),
            (int) $request->query('page', '1'),
            (int) $request->query('per_page', '15'),
        );

        return LegalBasisResource::collection($paginator)->response();
    }

    #[OA\Get(
        path: '/api/v1/legal-bases/{id}',
        operationId: 'legalBasesShow',
        tags: ['Base legal'],
        summary: 'Detalle de una base legal',
        description: 'Devuelve la base legal activa con ese id (las desactivadas responden 404; su terna sigue reservada).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Base legal',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/LegalBasis'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 404, description: 'Base legal inexistente o desactivada'),
        ],
    )]
    public function show(int $id): JsonResponse
    {
        $basis = $this->bases->get($id);

        abort_if($basis === null, 404, 'Legal basis not found.');

        return response()->json(['data' => new LegalBasisResource($basis)]);
    }

    #[OA\Post(
        path: '/api/v1/legal-bases',
        operationId: 'legalBasesStore',
        tags: ['Base legal'],
        summary: 'Registro de una base legal',
        description: 'Alta con validación de referencias, orden de fechas RN-006 (puesta en vigor ≥ emisión, derogación ≥ puesta en vigor) y unicidad de la terna tipo+número+año (RF-LEG-002); el año se deriva de la fecha de emisión (H-11) y nunca viaja en la petición. Toda escritura aterriza en la bitácora.',
        security: [['sanctumAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['legal_basis_type_id', 'number', 'issue_date', 'effective_date', 'issuing_organization_id'],
                properties: [
                    new OA\Property(property: 'legal_basis_type_id', type: 'integer', example: 1, description: 'Tipo (catálogo: Ley, Decreto-Ley, Decreto, Resolución, Indicación)'),
                    new OA\Property(property: 'number', type: 'string', example: '128', description: 'Número del documento'),
                    new OA\Property(property: 'issue_date', type: 'string', format: 'date', example: '2019-07-16', description: 'Fecha de emisión'),
                    new OA\Property(property: 'effective_date', type: 'string', format: 'date', example: '2019-08-01', description: 'Puesta en vigor (≥ issue_date, RN-006)'),
                    new OA\Property(property: 'derogation_date', type: 'string', format: 'date', nullable: true, description: 'Derogación opcional (≥ effective_date, RN-006); null = vigente'),
                    new OA\Property(property: 'issuing_organization_id', type: 'integer', example: 1, description: 'Organismo emisor (catálogo)'),
                    new OA\Property(property: 'reference', type: 'string', nullable: true, example: 'Gaceta Oficial Ordinaria No. 45 de 2019'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Base legal registrada con autoría estampada',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/LegalBasis'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function store(StoreLegalBasisRequest $request): JsonResponse
    {
        $basis = $this->bases->create($request->validated());

        return response()->json(['data' => new LegalBasisResource($basis)], 201);
    }

    #[OA\Patch(
        path: '/api/v1/legal-bases/{id}',
        operationId: 'legalBasesUpdate',
        tags: ['Base legal'],
        summary: 'Edición de una base legal',
        description: 'La terna identidad (tipo, número, fecha de emisión) es inmutable (422 si intenta cambiar). La derogación se fija, corrige o limpia aquí — una edición de fecha auditable con valores previos, nunca una acción destructiva. Las fechas resultantes revalidan RN-006.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'effective_date', type: 'string', format: 'date'),
                    new OA\Property(property: 'derogation_date', type: 'string', format: 'date', nullable: true, description: 'null limpia la derogación (vuelve a vigente)'),
                    new OA\Property(property: 'issuing_organization_id', type: 'integer'),
                    new OA\Property(property: 'reference', type: 'string', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Base legal actualizada (valores previos en la bitácora)',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/LegalBasis'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
            new OA\Response(response: 404, description: 'Base legal inexistente o desactivada'),
        ],
    )]
    public function update(UpdateLegalBasisRequest $request, int $id): JsonResponse
    {
        $basis = $this->bases->update($id, $request->validated());

        abort_if($basis === null, 404, 'Legal basis not found.');

        return response()->json(['data' => new LegalBasisResource($basis)]);
    }

    #[OA\Delete(
        path: '/api/v1/legal-bases/{id}',
        operationId: 'legalBasesDestroy',
        tags: ['Base legal'],
        summary: 'Desactivación de una base legal',
        description: 'Borrado lógico: la base sale de listados y detalle, pero su terna queda reservada y el borrado queda auditado. Futuras referencias de expedientes (F3) bloquearán el borrado físico por FK RESTRICT.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Base legal desactivada', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string', example: 'Legal basis deactivated.')],
            )),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 404, description: 'Base legal inexistente o ya desactivada'),
        ],
    )]
    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->bases->delete($id);

        abort_if(! $deleted, 404, 'Legal basis not found.');

        return response()->json(['message' => 'Legal basis deactivated.']);
    }
}
