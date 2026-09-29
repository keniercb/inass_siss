<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Controllers;

use App\Modules\Security\Application\Contracts\UserServiceInterface;
use App\Modules\Security\Application\Exceptions\PersonAlreadyLinkedException;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use App\Modules\Security\Presentation\Requests\LinkPersonRequest;
use App\Modules\Security\Presentation\Requests\ResetUserPasswordRequest;
use App\Modules\Security\Presentation\Requests\StoreUserRequest;
use App\Modules\Security\Presentation\Requests\UpdateUserRequest;
use App\Modules\Security\Presentation\Requests\UserIndexRequest;
use App\Modules\Security\Presentation\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * User account management HTTP surface (S3.5 + S3.6, RF-SEG-001,
 * RF-SEG-004, RF-AUD-004, ADR-24).
 *
 * Deliberately thin (ADR-11, ADR-12): validation arrives through
 * FormRequests, the account lifecycle use cases sit behind the
 * UserServiceInterface port and this layer only translates the
 * service outcomes into the response envelope (RF-API-002). Route
 * guards: users.view reads, users.manage writes (Administrador);
 * every write lands in the append-only bitácora through the
 * observers watching User (RF-AUD-001, ADR-19) with secrets
 * redacted (ADR-24).
 */
final class UserController
{
    public function __construct(
        private readonly UserServiceInterface $users,
    ) {}

    #[OA\Get(
        path: '/api/v1/users',
        operationId: 'usersIndex',
        tags: ['Usuarios'],
        summary: 'Directorio de cuentas',
        description: 'Cuentas paginadas con filtros q (nombre/email), role (rol institucional) y status (active por defecto, inactive desactivadas, all ambas). El estado derivado (locked/locked_until/password_expired) se resuelve al leer (RF-SEC-001). Requiere users.view (Administrador y Auditor, solo lectura).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\QueryParameter(name: 'q', required: false, schema: new OA\Schema(type: 'string', maxLength: 120), description: 'Texto libre sobre nombre o email (palabras en AND)'),
            new OA\QueryParameter(name: 'role', required: false, schema: new OA\Schema(type: 'string'), description: 'Rol exacto del directorio (institucional de la sección 2.2 o personalizado, ADR-26)'),
            new OA\QueryParameter(name: 'status', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'inactive', 'all'], default: 'active')),
            new OA\QueryParameter(name: 'page', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\QueryParameter(name: 'per_page', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resultados paginados con envelope RF-API-002',
                content: new OA\JsonContent(
                    required: ['data'],
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/User')),
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
    public function index(UserIndexRequest $request): JsonResponse
    {
        $paginator = $this->users->search(
            $request->filters(),
            (int) $request->query('page', '1'),
            (int) $request->query('per_page', '15'),
        );

        return UserResource::collection($paginator)->response();
    }

    #[OA\Get(
        path: '/api/v1/users/{id}',
        operationId: 'usersShow',
        tags: ['Usuarios'],
        summary: 'Detalle de una cuenta',
        description: 'Devuelve la cuenta activa con ese id (las desactivadas responden 404: su email y persona siguen reservados). Requiere users.view.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', required: true, schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Cuenta',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/User'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 404, description: 'Cuenta inexistente o desactivada'),
        ],
    )]
    public function show(int $id): UserResource
    {
        $user = $this->users->find($id);

        abort_if($user === null, 404, 'User not found.');

        return new UserResource($user);
    }

    #[OA\Post(
        path: '/api/v1/users',
        operationId: 'usersStore',
        tags: ['Usuarios'],
        summary: 'Registrar una cuenta',
        description: 'Crea la cuenta con contraseña inicial sujeta a la política de contraseñas (RF-SEC-001) y al menos un rol institucional. El email queda reservado: una cuenta activa o desactivada con esa dirección responde 422. Requiere users.manage (Administrador); la creación queda en la bitácora (contraseña redactada).',
        security: [['sanctumAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Cuenta a registrar',
            content: new OA\JsonContent(
                required: ['name', 'email', 'password', 'roles'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'María Operadora'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 255, example: 'maria@sgp.local'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'Segura2026', description: 'Sujeta a la política (longitud mínima y complejidad)'),
                    new OA\Property(property: 'roles', type: 'array', minItems: 1, items: new OA\Items(type: 'string', example: 'operator'), example: ['operator'], description: 'Roles del directorio: institucionales de la sección 2.2 o personalizados (ADR-26)'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Cuenta registrada',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/User'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function store(StoreUserRequest $request): JsonResponse
    {
        /** @var array{name: string, email: string, password: string, roles: list<string>} $payload */
        $payload = $request->validated();

        $user = $this->users->createUser(
            $payload['name'],
            $payload['email'],
            $payload['password'],
            $payload['roles'],
        );

        return response()->json([
            'data' => new UserResource($user),
        ], 201);
    }

    #[OA\Patch(
        path: '/api/v1/users/{id}',
        operationId: 'usersUpdate',
        tags: ['Usuarios'],
        summary: 'Editar una cuenta (nombre y roles)',
        description: 'Actualiza el nombre y/o la asignación completa de roles. El email es inmutable: enviar uno distinto responde 422. El sistema impide dejarlo sin ningún administrador activo (422). Requiere users.manage; la edición y el cambio de roles quedan en la bitácora con los valores previos.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', required: true, schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Campos a editar (parcial)',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'María Especialista'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', description: 'Solo se acepta igual al actual (inmutabilidad)'),
                    new OA\Property(property: 'roles', type: 'array', minItems: 1, items: new OA\Items(type: 'string', example: 'specialist'), example: ['specialist'], description: 'Asignación completa: institucionales de la sección 2.2 o personalizados (ADR-26)'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Cuenta actualizada',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/User'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 404, description: 'Cuenta inexistente o desactivada'),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        /** @var array{name?: string, email?: string, roles?: list<string>} $payload */
        $payload = $request->validated();

        $user = $this->users->updateUser(
            $id,
            $payload['name'] ?? null,
            $payload['roles'] ?? null,
            $payload['email'] ?? null,
        );

        abort_if($user === null, 404, 'User not found.');

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/users/{id}',
        operationId: 'usersDestroy',
        tags: ['Usuarios'],
        summary: 'Desactivar una cuenta',
        description: 'Borrado lógico (RF-AUD-004): la cuenta desactivada reserva su email y persona vinculada, no puede autenticarse (login 401) y pierde todas sus sesiones. Un administrador no puede desactivarse a sí mismo ni al último administrador activo (422). Requiere users.manage; auditeda con los valores previos.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', required: true, schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Cuenta desactivada'),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 404, description: 'Cuenta inexistente o ya desactivada'),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function destroy(Request $request, int $id): JsonResponse
    {
        // The route is protected by auth:sanctum, so the user is always
        // resolved here; the assert documents the invariant for PHPStan.
        $actor = $request->user();
        assert($actor instanceof User);

        $user = $this->users->deactivate($id, (int) $actor->id);

        abort_if($user === null, 404, 'User not found.');

        return response()->json(status: 204);
    }

    #[OA\Post(
        path: '/api/v1/users/{id}/restore',
        operationId: 'usersRestore',
        tags: ['Usuarios'],
        summary: 'Reactivar una cuenta desactivada',
        description: 'Restauración exclusiva del Administrador (RF-AUD-004), auditada. Idempotente para una cuenta ya activa (200 sin escritura). Requiere users.manage.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', required: true, schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Cuenta reactivada (o ya activa: idempotente)',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/User'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 404, description: 'Cuenta inexistente'),
        ],
    )]
    public function restore(int $id): JsonResponse
    {
        $user = $this->users->restore($id);

        abort_if($user === null, 404, 'User not found.');

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/users/{id}/unlock',
        operationId: 'usersUnlock',
        tags: ['Usuarios'],
        summary: 'Desbloquear una cuenta bloqueada por intentos fallidos',
        description: 'Desbloqueo anticipado por el Administrador (RF-SEG-001): limpia el contador y el instante de bloqueo antes de que expire el TTL. Idempotente para una cuenta limpia (200 sin escritura). Requiere users.manage; el desbloqueo queda en la bitácora con el estado previo.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', required: true, schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Cuenta desbloqueada (o ya limpia: idempotente)',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/User'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 404, description: 'Cuenta inexistente o desactivada'),
        ],
    )]
    public function unlock(int $id): JsonResponse
    {
        $user = $this->users->unlock($id);

        abort_if($user === null, 404, 'User not found.');

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }

    #[OA\Patch(
        path: '/api/v1/users/{id}/password',
        operationId: 'usersResetPassword',
        tags: ['Usuarios'],
        summary: 'Restablecer la contraseña de una cuenta',
        description: 'Restablecimiento por el Administrador (RF-SEG-001): la nueva contraseña se valida contra la política, se renueva la línea base de caducidad, se limpia el bloqueo y se revocan TODAS las sesiones de la cuenta. Requiere users.manage; la escritura queda en la bitácora con la contraseña redactada.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', required: true, schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Nueva contraseña',
            content: new OA\JsonContent(
                required: ['password'],
                properties: [
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'Renovada2026', description: 'Sujeta a la política (longitud mínima y complejidad)'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Contraseña restablecida (todas las sesiones revocadas)',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/User'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 404, description: 'Cuenta inexistente o desactivada'),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function resetPassword(ResetUserPasswordRequest $request, int $id): JsonResponse
    {
        $user = $this->users->resetPassword($id, (string) $request->validated('password'));

        abort_if($user === null, 404, 'User not found.');

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }

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
