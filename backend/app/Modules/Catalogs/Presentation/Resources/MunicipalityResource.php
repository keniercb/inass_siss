<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Presentation\Resources;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\Municipality;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * @mixin Municipality
 */
#[OA\Schema(
    schema: 'Municipality',
    title: 'Municipio',
    description: 'Municipio cubano con su provincia (RF-CAT-002). province es null para el municipio especial Isla de la Juventud.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 42),
        new OA\Property(property: 'code', type: 'string', example: '07'),
        new OA\Property(property: 'name', type: 'string', example: 'Holguín'),
        new OA\Property(
            property: 'province',
            nullable: true,
            description: 'Provincia del municipio; null para el municipio especial',
            type: 'object',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 12),
                new OA\Property(property: 'code', type: 'string', example: '12'),
                new OA\Property(property: 'name', type: 'string', example: 'Holguín'),
            ],
        ),
        new OA\Property(property: 'deactivated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
final class MunicipalityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'province' => $this->whenLoaded('province', fn () => [
                'id' => $this->province?->id,
                'code' => $this->province?->code,
                'name' => $this->province?->name,
            ]),
            'deactivated_at' => $this->deleted_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
