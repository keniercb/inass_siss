<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Controllers;

use App\Modules\Security\Application\Contracts\UserServiceInterface;
use App\Modules\Security\Application\Exceptions\PersonAlreadyLinkedException;
use App\Modules\Security\Presentation\Requests\LinkPersonRequest;
use App\Modules\Security\Presentation\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * User account management HTTP surface (S3.5, RF-SEG-004).
 *
 * Deliberately thin (ADR-11, ADR-12): validation arrives through
 * LinkPersonRequest, the association use cases sit behind the
 * UserServiceInterface port and this layer only translates the
 * service outcomes into the response envelope (RF-API-002). The
 * uniqueness rule (one person backs at most one account) is the
 * service's business rule with the users.person_id constraint as
 * the database backstop; both link and unlink writes land in the
 * append-only bitácora through the observers already watching User
 * (RF-AUD-001, ADR-19). Route guard: users.manage (Administrador).
 */
final class UserController
{
    public function __construct(
        private readonly UserServiceInterface $users,
    ) {}

    #[OA\Post(
        path: '/api/v1/users/{id}/person',
        operationId: 'usersLinkPerson',
        tags: ['Usuarios'],
        summary: 'Vincular una cuenta con una persona del registro único',
        description: 'Asocia la cuenta a una persona registrada para la trazabilidad de acciones (RF-SEG-004). Idempotente para la misma pareja cuenta-persona (200). La persona ya vinculada a otra cuenta responde 409 con la cuenta dueña; las cuentas desactivadas también reservan a su persona. Requiere el permiso users.manage (Administrador) y queda registrada en la bitácora con el valor previo.',
        security: [['sanctumAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Persona a vincular',
            content: new OA\JsonContent(
                required: ['person_id'],
                properties: [
                    new OA\Property(property: 'person_id', type: 'integer', format: 'int64', example: 1, description: 'Identificador de la persona en el registro único'),
                ],
            ),
        ),
        parameters: [
            new OA\PathParameter(name: 'id', required: true, schema: new OA\Schema(type: 'integer', format: 'int64'), description: 'Identificador de la cuenta'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Vinculación aplicada (o ya vigente: idempotente)',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/User'),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(
                response: 403,
                description: 'Sin permiso users.manage',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Forbidden.')],
                ),
            ),
            new OA\Response(
                response: 404,
                description: 'Cuenta inexistente',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Not Found')],
                ),
            ),
            new OA\Response(
                response: 409,
                description: 'La persona ya pertenece a otra cuenta',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'The person is already linked to another user.'),
                        new OA\Property(property: 'person_id', type: 'integer', format: 'int64', example: 1),
                        new OA\Property(property: 'linked_to_user_id', type: 'integer', format: 'int64', example: 3, description: 'Cuenta que actualmente posee a la persona (incluye desactivadas)'),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function linkPerson(LinkPersonRequest $request, int $id): JsonResponse
    {
        try {
            $user = $this->users->linkPerson($id, (int) $request->validated('person_id'));
        } catch (PersonAlreadyLinkedException $exception) {
            return response()->json([
                'message' => 'The person is already linked to another user.',
                'person_id' => $exception->personId,
                'linked_to_user_id' => $exception->currentUserId,
            ], 409);
        }

        abort_if($user === null, 404, 'User not found.');

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/users/{id}/person',
        operationId: 'usersUnlinkPerson',
        tags: ['Usuarios'],
        summary: 'Desvincular la persona de una cuenta',
        description: 'Elimina la asociación cuenta-persona (RF-SEG-004). Idempotente: desvincular una cuenta sin persona responde 200. Requiere el permiso users.manage (Administrador) y el cambio queda en la bitácora con la persona previa.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', required: true, schema: new OA\Schema(type: 'integer', format: 'int64'), description: 'Identificador de la cuenta'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Asociación eliminada (o inexistente: idempotente)',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/User'),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(
                response: 403,
                description: 'Sin permiso users.manage',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Forbidden.')],
                ),
            ),
            new OA\Response(
                response: 404,
                description: 'Cuenta inexistente',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Not Found')],
                ),
            ),
        ],
    )]
    public function unlinkPerson(int $id): JsonResponse
    {
        $user = $this->users->unlinkPerson($id);

        abort_if($user === null, 404, 'User not found.');

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }
}
