<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Presentation\Controllers;

use App\Modules\Organizations\Application\Contracts\SignatureServiceInterface;
use App\Modules\Organizations\Presentation\Requests\SignatureIndexRequest;
use App\Modules\Organizations\Presentation\Requests\StoreSignatureRequest;
use App\Modules\Organizations\Presentation\Requests\UpdateSignatureRequest;
use App\Modules\Organizations\Presentation\Resources\AuthorizedSignatureResource;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * HTTP surface for authorized signatures (RF-ENT-003).
 *
 * Thin by design (ADR-11/12): the tern uniqueness, the window
 * ordering (RN-006) and the revocation semantics live in the
 * SignatureService behind the SignatureServiceInterface port. The
 * status of every signature is derived at read time (Domain
 * SignatureStatus over the Shared Clock), never stored.
 */
final class AuthorizedSignatureController
{
    public function __construct(
        private readonly SignatureServiceInterface $signatures,
    ) {}

    #[OA\Get(
        path: '/api/v1/authorized-signatures',
        operationId: 'signaturesIndex',
        tags: ['Estructura'],
        summary: 'Listado paginado de firmas autorizadas',
        description: 'Firmas con sus referencias anidadas y el estado derivado de la ventana de vigencia (RF-ENT-003): filtros por entidad, persona, cargo y estado (active/future/expired).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\QueryParameter(name: 'entity_id', schema: new OA\Schema(type: 'integer', nullable: true)),
            new OA\QueryParameter(name: 'person_id', schema: new OA\Schema(type: 'integer', nullable: true)),
            new OA\QueryParameter(name: 'position_id', schema: new OA\Schema(type: 'integer', nullable: true)),
            new OA\QueryParameter(name: 'status', schema: new OA\Schema(type: 'string', enum: ['active', 'future', 'expired'], nullable: true)),
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
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AuthorizedSignature')),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'total', type: 'integer', example: 8),
                                new OA\Property(property: 'last_page', type: 'integer', example: 1),
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
    public function index(SignatureIndexRequest $request): JsonResponse
    {
        $paginator = $this->signatures->search(
            $request->filters(),
            (int) $request->query('page', '1'),
            (int) $request->query('per_page', '15'),
        );

        return AuthorizedSignatureResource::collection($paginator)->response();
    }

    #[OA\Get(
        path: '/api/v1/authorized-signatures/{id}',
        operationId: 'signaturesShow',
        tags: ['Estructura'],
        summary: 'Detalle de una firma autorizada',
        description: 'Devuelve la firma con ese id (las revocadas responden 404: su fila permanece como historial auditable).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Firma autorizada',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/AuthorizedSignature'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 404, description: 'Firma inexistente o revocada'),
        ],
    )]
    public function show(int $id): JsonResponse
    {
        $signature = $this->signatures->get($id);

        abort_if($signature === null, 404, 'Signature not found.');

        return response()->json(['data' => new AuthorizedSignatureResource($signature)]);
    }

    #[OA\Post(
        path: '/api/v1/authorized-signatures',
        operationId: 'signaturesStore',
        tags: ['Estructura'],
        summary: 'Registro de una firma autorizada',
        description: 'Vincula entidad, persona y cargo (RF-ENT-003): la terna es única y queda reservada por el historial de revocación (422 si ya existe, incluidas las revocadas). La ventana de vigencia es opcional (RN-006: fin ≥ inicio) y el estado se deriva al leer. Toda escritura aterriza en la bitácora.',
        security: [['sanctumAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['entity_id', 'person_id', 'position_id'],
                properties: [
                    new OA\Property(property: 'entity_id', type: 'integer', example: 1, description: 'Entidad que autoriza'),
                    new OA\Property(property: 'person_id', type: 'integer', example: 5, description: 'Persona autorizada (registro único)'),
                    new OA\Property(property: 'position_id', type: 'integer', example: 2, description: 'Cargo en que firma (catálogo)'),
                    new OA\Property(property: 'valid_from', type: 'string', format: 'date', nullable: true, example: '2020-01-01'),
                    new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true, example: '2030-12-31', description: '≥ valid_from (RN-006)'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Firma registrada con autoría estampada',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/AuthorizedSignature'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function store(StoreSignatureRequest $request): JsonResponse
    {
        $signature = $this->signatures->create($request->validated());

        return response()->json(['data' => new AuthorizedSignatureResource($signature)], 201);
    }

    #[OA\Patch(
        path: '/api/v1/authorized-signatures/{id}',
        operationId: 'signaturesUpdate',
        tags: ['Estructura'],
        summary: 'Edición de la ventana de vigencia',
        description: 'La terna identifica el registro histórico y no es editable (RF-ENT-003: el versionado de firmas es la propia fila con su ventana). Solo viajan las fechas; la ventana resultante revalida el orden RN-006 y la edición queda auditada con valores previos.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'valid_from', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Firma actualizada (valores previos en la bitácora)',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/AuthorizedSignature'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
            new OA\Response(response: 404, description: 'Firma inexistente o revocada'),
        ],
    )]
    public function update(UpdateSignatureRequest $request, int $id): JsonResponse
    {
        $signature = $this->signatures->update($id, $request->validated());

        abort_if($signature === null, 404, 'Signature not found.');

        return response()->json(['data' => new AuthorizedSignatureResource($signature)]);
    }

    #[OA\Delete(
        path: '/api/v1/authorized-signatures/{id}',
        operationId: 'signaturesDestroy',
        tags: ['Estructura'],
        summary: 'Revocación de una firma',
        description: 'Borrado lógico: la fila queda como historial de la firma (RF-ENT-003) y mantiene la terna reservada — no puede registrarse de nuevo la misma combinación. La revocación aterriza en la bitácora. Futuras referencias (expedientes de F3) bloquearán el borrado físico por FK RESTRICT.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Firma revocada', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string', example: 'Signature revoked.')],
            )),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 404, description: 'Firma inexistente o ya revocada'),
        ],
    )]
    public function destroy(int $id): JsonResponse
    {
        $revoked = $this->signatures->delete($id);

        abort_if(! $revoked, 404, 'Signature not found.');

        return response()->json(['message' => 'Signature revoked.']);
    }
}
