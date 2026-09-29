<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Resources;

use App\Modules\Security\Application\DTO\PermissionEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** @mixin PermissionEntry */
#[OA\Schema(
    schema: 'Permission',
    title: 'Permiso',
    description: 'Entrada del catálogo de permisos (RF-SEG-002, ADR-27): artefacto de código propiedad de la PermissionMatrix, expuesto como superficie de solo lectura para construir el selector de concesiones del editor de roles. Los permisos no se crean ni editan en runtime: la matriz es su única fuente de verdad y la extiende el código.',
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'people.view', description: 'Clave natural modulo.accion del catálogo'),
        new OA\Property(property: 'module', type: 'string', example: 'people', description: 'Módulo al que pertenece el permiso'),
        new OA\Property(property: 'action', type: 'string', example: 'view', description: 'Acción (ver, crear, editar, aprobar, exportar…)'),
        new OA\Property(
            property: 'institutional_roles',
            type: 'array',
            description: 'Roles institucionales (sección 2.2) que lo otorgan según la matriz, en orden de la sección',
            items: new OA\Items(type: 'string', example: 'operator'),
        ),
        new OA\Property(
            property: 'custom_roles',
            type: 'array',
            description: 'Roles personalizados que lo incluyen en su conjunto, orden alfabético',
            items: new OA\Items(type: 'string', example: 'supervisor_territorial'),
        ),
        new OA\Property(
            property: 'users_count',
            type: 'integer',
            description: 'Cuentas que pueden actuar con este permiso a través de los roles que lo otorgan (incluye desactivadas: sus pivotes reservan los roles)',
            example: 3,
        ),
    ],
)]
final class PermissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'module' => $this->module,
            'action' => $this->action,
            'institutional_roles' => $this->institutionalRoles,
            'custom_roles' => $this->customRoles,
            'users_count' => $this->usersCount,
        ];
    }
}
