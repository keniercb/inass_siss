<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Resources;

use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** @mixin User */
#[OA\Schema(
    schema: 'User',
    title: 'Usuario',
    description: 'Usuario del sistema SGP con sus roles y permisos efectivos (RF-SEG-002).',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'SGP Demo Admin'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@sgp.local'),
        new OA\Property(
            property: 'roles',
            type: 'array',
            description: 'Roles institucionales asignados (sección 2.2)',
            items: new OA\Items(type: 'string', example: 'operator'),
        ),
        new OA\Property(
            property: 'permissions',
            type: 'array',
            description: 'Permisos efectivos (de roles y directos), orden alfabético',
            items: new OA\Items(type: 'string', example: 'people.create'),
        ),
        new OA\Property(
            property: 'person',
            nullable: true,
            description: 'Persona del registro único vinculada a la cuenta (RF-SEG-004); null mientras no exista asociación',
            ref: '#/components/schemas/LinkedPerson',
        ),
    ],
)]
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->getRoleNames()->sort()->values()->all(),
            'permissions' => $this->getAllPermissions()->pluck('name')->sort()->values()->all(),
            'person' => $this->person !== null ? new LinkedPersonResource($this->person) : null,
        ];
    }
}
