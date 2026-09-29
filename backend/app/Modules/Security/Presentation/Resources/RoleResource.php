<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Resources;

use App\Modules\Security\Infrastructure\Persistence\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** @mixin Role */
#[OA\Schema(
    schema: 'Role',
    title: 'Rol',
    description: 'Rol del sistema: los cinco institucionales de la sección 2.2 (is_system = true, inmutables: sus permisos viven en la matriz) y los personalizados creados por el Administrador como subconjuntos del catálogo de permisos (RF-SEG-002, ADR-26).',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 6),
        new OA\Property(property: 'name', type: 'string', example: 'supervisor_territorial', description: 'Clave natural (slug minúscula); los nombres institucionales están reservados'),
        new OA\Property(property: 'description', type: 'string', nullable: true, maxLength: 255, example: 'Supervisa la captura de una provincia'),
        new OA\Property(
            property: 'is_system',
            type: 'boolean',
            description: 'true para los roles institucionales de la sección 2.2 (sembrados desde la matriz, inmutables vía API); false para los personalizados',
        ),
        new OA\Property(
            property: 'permissions',
            type: 'array',
            description: 'Permisos otorgados al rol, subconjunto del catálogo de la matriz, orden alfabético',
            items: new OA\Items(type: 'string', example: 'people.view'),
        ),
        new OA\Property(
            property: 'users_count',
            type: 'integer',
            description: 'Cuentas que ostentan el rol (incluye desactivadas: sus pivotes reservan el rol, igual que la guarda de borrado)',
        ),
    ],
)]
final class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_system' => (bool) $this->is_system,
            'permissions' => $this->permissions
                ->pluck('name')
                ->sort()
                ->values()
                ->all(),
            'users_count' => (int) ($this->users_count ?? 0),
        ];
    }
}
