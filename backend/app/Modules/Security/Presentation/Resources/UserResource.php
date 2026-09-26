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
    description: 'Usuario del sistema SGP.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'SGP Demo Admin'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@sgp.local'),
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
        ];
    }
}
