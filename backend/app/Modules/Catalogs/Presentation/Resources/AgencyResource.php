<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Presentation\Resources;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\Agency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * @mixin Agency
 */
#[OA\Schema(
    schema: 'Agency',
    title: 'Agencia bancaria',
    description: 'Agencia bancaria con provincia, municipio y tipo (RF-CAT-003). La pareja municipio-provincia es coherente por construcción (RN-04).',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 7),
        new OA\Property(property: 'code', type: 'string', example: 'BPA0101'),
        new OA\Property(property: 'name', type: 'string', example: 'Agencia 1 BPA Holguín'),
        new OA\Property(
            property: 'type',
            type: 'object',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
                new OA\Property(property: 'code', type: 'string', example: 'AG'),
                new OA\Property(property: 'name', type: 'string', example: 'Agencia'),
            ],
        ),
        new OA\Property(
            property: 'province',
            type: 'object',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 12),
                new OA\Property(property: 'code', type: 'string', example: '12'),
                new OA\Property(property: 'name', type: 'string', example: 'Holguín'),
            ],
        ),
        new OA\Property(
            property: 'municipality',
            type: 'object',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 42),
                new OA\Property(property: 'code', type: 'string', example: '07'),
                new OA\Property(property: 'name', type: 'string', example: 'Holguín'),
            ],
        ),
        new OA\Property(property: 'deactivated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
final class AgencyResource extends JsonResource
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
            'type' => $this->whenLoaded('agencyType', fn () => [
                'id' => $this->agencyType?->id,
                'code' => $this->agencyType?->code,
                'name' => $this->agencyType?->name,
            ]),
            'province' => $this->whenLoaded('province', fn () => [
                'id' => $this->province?->id,
                'code' => $this->province?->code,
                'name' => $this->province?->name,
            ]),
            'municipality' => $this->whenLoaded('municipality', fn () => [
                'id' => $this->municipality?->id,
                'code' => $this->municipality?->code,
                'name' => $this->municipality?->name,
            ]),
            'deactivated_at' => $this->deleted_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
