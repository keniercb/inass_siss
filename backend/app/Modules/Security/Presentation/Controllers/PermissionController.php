<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Controllers;

use App\Modules\Security\Application\Contracts\PermissionServiceInterface;
use App\Modules\Security\Presentation\Resources\PermissionResource;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Permission catalog HTTP surface (RF-SEG-002, ADR-27).
 *
 * Deliberately thin (ADR-11, ADR-12): the catalog use cases sit
 * behind the PermissionServiceInterface port and this layer only
 * translates the outcomes into the response envelope (RF-API-002).
 *
 * Read-only by design: permissions are code artifacts — the
 * PermissionMatrix is their single source of truth and the seeder
 * materializes it — so this surface exposes no store/update/
 * destroy pathway; a runtime-created permission would desynchronize
 * the code (dataset, middleware expectations, guards) from the
 * database. The role editor consumes the catalog to build its grant
 * picker (the same catalog also travels as meta.permissions on GET
 * /roles). Route guard: roles.view, the same read surface as the
 * role directory (Administrador y Auditor — the Auditor cross-reads
 * while resolving bitácora subjects, ADR-24 precedent).
 */
final class PermissionController
{
    public function __construct(
        private readonly PermissionServiceInterface $permissions,
    ) {}

    #[OA\Get(
        path: '/api/v1/permissions',
        operationId: 'permissionsIndex',
        tags: ['Roles'],
        summary: 'Catálogo de permisos',
        description: 'El catálogo completo de permisos asignables (PermissionMatrix: la única fuente de verdad, code-owned), cada uno descompuesto en módulo y acción, con los roles institucionales que lo otorgan según la matriz, los roles personalizados que lo incluyen y el número de cuentas que pueden actuar con él (incluye desactivadas: sus pivotes reservan los roles). Superficie de solo lectura que el editor de roles consume para construir el selector de concesiones. Requiere roles.view (Administrador y Auditor).',
        security: [['sanctumAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Catálogo de permisos con envelope RF-API-002',
                content: new OA\JsonContent(
                    required: ['data'],
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Permission')),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
        ],
    )]
    public function index(): JsonResponse
    {
        return PermissionResource::collection($this->permissions->list()->all())
            ->response();
    }

    #[OA\Get(
        path: '/api/v1/permissions/{permission}',
        operationId: 'permissionsShow',
        tags: ['Roles'],
        summary: 'Detalle de un permiso',
        description: 'Un permiso del catálogo por su clave natural modulo.accion: descomposición, roles institucionales que lo otorgan, roles personalizados que lo incluyen y cuentas con acceso efectivo. La ruta solo admite nombres minúscula modulo.accion; un nombre bien formado que no pertenece al catálogo responde 404. Requiere roles.view.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(
                name: 'permission',
                required: true,
                description: 'Clave natural del permiso',
                schema: new OA\Schema(type: 'string', example: 'people.view'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Permiso',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Permission'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 404, description: 'Permiso inexistente o nombre malformado'),
        ],
    )]
    public function show(string $permission): PermissionResource
    {
        $entry = $this->permissions->find($permission);

        abort_if($entry === null, 404, 'Permission not found.');

        return new PermissionResource($entry);
    }
}
