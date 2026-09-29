<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Controllers;

use App\Modules\Security\Application\Contracts\RoleServiceInterface;
use App\Modules\Security\Application\Exceptions\RoleInUseException;
use App\Modules\Security\Domain\Authorization\PermissionMatrix;
use App\Modules\Security\Infrastructure\Persistence\Models\Role;
use App\Modules\Security\Presentation\Requests\StoreRoleRequest;
use App\Modules\Security\Presentation\Requests\UpdateRoleRequest;
use App\Modules\Security\Presentation\Resources\RoleResource;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Role management HTTP surface (RF-SEG-002, ADR-26).
 *
 * Deliberately thin (ADR-11, ADR-12): validation arrives through
 * FormRequests, the management use cases sit behind the
 * RoleServiceInterface port and this layer only translates the
 * service outcomes into the response envelope (RF-API-002). Route
 * guards: roles.view reads (Administrador y Auditor — el Auditor
 * resuelve sujetos de bitácora), roles.manage writes
 * (Administrador). Institutional roles answer 422 for any write:
 * the PermissionMatrix is their single source of truth. Every write
 * lands in the append-only bitácora (row events + explicit entries
 * for the permission pivots, ADR-19/ADR-26).
 */
final class RoleController
{
    public function __construct(
        private readonly RoleServiceInterface $roles,
    ) {}

    #[OA\Get(
        path: '/api/v1/roles',
        operationId: 'rolesIndex',
        tags: ['Roles'],
        summary: 'Directorio de roles',
        description: 'Los cinco roles institucionales de la sección 2.2 (is_system = true) y los personalizados, cada uno con su conjunto de permisos y el número de cuentas que lo ostentan (incluye desactivadas: sus pivotes reservan el rol). El catálogo completo de permisos viaja en meta.permissions para construir el selector de concesiones. Requiere roles.view (Administrador y Auditor, solo lectura).',
        security: [['sanctumAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Directorio de roles con envelope RF-API-002',
                content: new OA\JsonContent(
                    required: ['data'],
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Role')),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(
                                    property: 'permissions',
                                    type: 'array',
                                    description: 'Catálogo de permisos asignables (PermissionMatrix)',
                                    items: new OA\Items(type: 'string', example: 'people.view'),
                                ),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
        ],
    )]
    public function index(): JsonResponse
    {
        return RoleResource::collection($this->roles->list()->all())
            ->additional([
                'meta' => [
                    'permissions' => PermissionMatrix::permissions(),
                ],
            ])
            ->response();
    }

    #[OA\Get(
        path: '/api/v1/roles/{id}',
        operationId: 'rolesShow',
        tags: ['Roles'],
        summary: 'Detalle de un rol',
        description: 'Devuelve el rol institucional o personalizado con su conjunto de permisos y su número de cuentas. Requiere roles.view.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', required: true, schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Rol',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Role'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 404, description: 'Rol inexistente'),
        ],
    )]
    public function show(int $id): RoleResource
    {
        $role = $this->roles->find($id);

        abort_if($role === null, 404, 'Role not found.');

        return new RoleResource($role);
    }

    #[OA\Post(
        path: '/api/v1/roles',
        operationId: 'rolesStore',
        tags: ['Roles'],
        summary: 'Registrar un rol personalizado',
        description: 'Crea un rol personalizado con un subconjunto del catálogo de permisos (RF-SEG-002: permisos asignables a roles). El nombre es un slug minúsculo único; los nombres institucionales de la sección 2.2 están reservados (422) y el catálogo completo cierra el conjunto válido (422). Requiere roles.manage (Administrador); la creación y la concesión inicial quedan en la bitácora.',
        security: [['sanctumAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Rol a registrar',
            content: new OA\JsonContent(
                required: ['name', 'permissions'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 31, example: 'supervisor_territorial', description: 'Slug minúsculo (letras, dígitos, guiones bajos) que empieza por letra'),
                    new OA\Property(property: 'description', type: 'string', maxLength: 255, nullable: true, example: 'Supervisa la captura de una provincia'),
                    new OA\Property(property: 'permissions', type: 'array', minItems: 1, items: new OA\Items(type: 'string', example: 'people.view'), example: ['cases.view', 'people.view']),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Rol registrado',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Role'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function store(StoreRoleRequest $request): JsonResponse
    {
        /** @var array{name: string, description?: string|null, permissions: list<string>} $payload */
        $payload = $request->validated();

        $role = $this->roles->create(
            $payload['name'],
            $payload['description'] ?? null,
            $payload['permissions'],
        );

        return response()->json([
            'data' => new RoleResource($role),
        ], 201);
    }

    #[OA\Patch(
        path: '/api/v1/roles/{id}',
        operationId: 'rolesUpdate',
        tags: ['Roles'],
        summary: 'Editar un rol personalizado',
        description: 'Actualiza el nombre, la descripción y/o el conjunto completo de permisos (PATCH parcial: lo ausente no cambia; permissions reemplaza el set entero). Los roles institucionales son inmutables (422: sus permisos viven en la matriz, no en la base de datos). El nombre sigue el contrato de slug y la unicidad (422). Requiere roles.manage; la edición y el cambio de concesiones quedan en la bitácora con los valores previos.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', required: true, schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Campos a editar (parcial)',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 31, example: 'supervisor_nacional', description: 'Slug minúsculo; solo para roles personalizados'),
                    new OA\Property(property: 'description', type: 'string', maxLength: 255, nullable: true, example: 'Alcance nacional'),
                    new OA\Property(property: 'permissions', type: 'array', minItems: 1, items: new OA\Items(type: 'string', example: 'people.view'), example: ['cases.view', 'people.view'], description: 'Reemplaza el conjunto completo de concesiones'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Rol actualizado',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Role'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 404, description: 'Rol inexistente'),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function update(UpdateRoleRequest $request, int $id): JsonResponse
    {
        /** @var array{name?: string, description?: string|null, permissions?: list<string>} $payload */
        $payload = $request->validated();

        $role = $this->roles->update(
            $id,
            $payload['name'] ?? null,
            $payload['description'] ?? null,
            $payload['permissions'] ?? null,
        );

        abort_if($role === null, 404, 'Role not found.');

        return response()->json([
            'data' => new RoleResource($role),
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/roles/{id}',
        operationId: 'rolesDestroy',
        tags: ['Roles'],
        summary: 'Eliminar un rol personalizado',
        description: 'Elimina el rol y sus concesiones (los pivotes caen en cascada); la bitácora conserva el nombre y el conjunto de permisos que portaba. Un rol que cuentas aún ostentan (activas o desactivadas) responde 409 con el número de cuentas; los institucionales responden 422. Requiere roles.manage.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', required: true, schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Rol eliminado'),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 404, description: 'Rol inexistente'),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
            new OA\Response(
                response: 409,
                description: 'El rol sigue asignado a cuentas',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'The role is still assigned to accounts. Unassign it before deleting it.'),
                        new OA\Property(property: 'role_id', type: 'integer', format: 'int64', example: 6),
                        new OA\Property(property: 'users_count', type: 'integer', example: 3, description: 'Cuentas que ostentan el rol, incluidas desactivadas'),
                    ],
                ),
            ),
        ],
    )]
    public function destroy(int $id): JsonResponse
    {
        try {
            $deleted = $this->roles->delete($id);
        } catch (RoleInUseException $exception) {
            return response()->json([
                'message' => 'The role is still assigned to accounts. Unassign it before deleting it.',
                'role_id' => $exception->roleId,
                'users_count' => $exception->usersCount,
            ], 409);
        }

        abort_if(! $deleted, 404, 'Role not found.');

        return response()->json(status: 204);
    }
}
