<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Presentation\Resources;

use App\Modules\Organizations\Infrastructure\Persistence\Models\Office;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Office projection (RF-ENT-002/005): geographic scope, type and the
 * direct parent summary. Offices carry no natural key.
 *
 * @mixin Office
 */
#[OA\Schema(
    schema: 'Office',
    title: 'Oficina',
    description: 'Oficina del Ministerio (RF-ENT-002). La pareja municipio-provincia es coherente (RN-04) y la jerarquía (parent) acíclica (RN-003).',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 3),
        new OA\Property(
            property: 'type',
            type: 'object',
            description: 'Tipo de oficina (nacional/provincial/municipal)',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
                new OA\Property(property: 'code', type: 'string', example: 'NAC'),
                new OA\Property(property: 'name', type: 'string', example: 'Nacional'),
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
        new OA\Property(property: 'address', type: 'string', example: 'Calle Martí #100, Holguín'),
        new OA\Property(property: 'parent_office_id', type: 'integer', format: 'int64', nullable: true, description: 'Oficina superior (jerarquía acíclica RN-003)'),
        new OA\Property(property: 'parent', type: 'object', nullable: true, description: 'Resumen de la oficina superior'),
    ],
)]
final class OfficeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->whenLoaded('officeType', fn () => [
                'id' => $this->officeType?->id,
                'code' => $this->officeType?->code,
                'name' => $this->officeType?->name,
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
            'address' => $this->address,
            'parent_office_id' => $this->parent_office_id,
            'parent' => $this->whenLoaded('parent', fn () => $this->parent === null ? null : [
                'id' => $this->parent->id,
                'address' => $this->parent->address,
                'type' => $this->parent->officeType === null ? null : [
                    'id' => $this->parent->officeType->id,
                    'code' => $this->parent->officeType->code,
                    'name' => $this->parent->officeType->name,
                ],
            ]),
        ];
    }
}
