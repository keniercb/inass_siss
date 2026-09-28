<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Presentation\Resources;

use App\Modules\Organizations\Infrastructure\Persistence\Models\Entity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Entity projection (RF-ENT-001/005): the natural keys, contact
 * data, directors and the direct parent summary. The directors
 * reference the People registry by id — person details belong to the
 * People module surface.
 *
 * @mixin Entity
 */
#[OA\Schema(
    schema: 'Entity',
    title: 'Entidad',
    description: 'Entidad empleadora / centro de trabajo (RF-ENT-001). Código y NIT únicos e inmutables; la jerarquía (parent) es acíclica (RN-003) y la pareja municipio-provincia coherente (RN-04).',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'code', type: 'string', example: 'ENT-0001', description: 'Código único; queda reservado tras desactivar'),
        new OA\Property(property: 'tax_id_number', type: 'string', example: '11000012345', description: 'NIT único e inmutable'),
        new OA\Property(
            property: 'organization',
            type: 'object',
            description: 'Organismo de pertenencia (catálogo)',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
                new OA\Property(property: 'code', type: 'string', example: 'MTSS'),
                new OA\Property(property: 'name', type: 'string', example: 'Ministerio de Trabajo y Seguridad Social'),
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
        new OA\Property(
            property: 'type',
            type: 'object',
            description: 'Tipo de entidad (catálogo)',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
                new OA\Property(property: 'code', type: 'string', example: 'EMP'),
                new OA\Property(property: 'name', type: 'string', example: 'Empresa'),
            ],
        ),
        new OA\Property(property: 'address', type: 'string', example: 'Calle 1 #2, Holguín'),
        new OA\Property(property: 'phone', type: 'string', nullable: true, example: '024 461234'),
        new OA\Property(property: 'fax', type: 'string', nullable: true),
        new OA\Property(property: 'email', type: 'string', nullable: true, example: 'contacto@ent.gob.cu'),
        new OA\Property(property: 'director_person_id', type: 'integer', format: 'int64', nullable: true, description: 'Director general (persona registrada, RF-ENT-001)'),
        new OA\Property(property: 'economic_director_person_id', type: 'integer', format: 'int64', nullable: true, description: 'Director económico (persona registrada)'),
        new OA\Property(property: 'parent_entity_id', type: 'integer', format: 'int64', nullable: true, description: 'Entidad superior (jerarquía acíclica RN-003)'),
        new OA\Property(property: 'parent', type: 'object', nullable: true, description: 'Resumen de la entidad superior'),
        new OA\Property(property: 'social_purpose', type: 'string', example: 'Servicios técnicos especializados'),
    ],
)]
final class EntityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'tax_id_number' => $this->tax_id_number,
            'organization' => $this->whenLoaded('organization', fn () => [
                'id' => $this->organization?->id,
                'code' => $this->organization?->code,
                'name' => $this->organization?->name,
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
            'type' => $this->whenLoaded('entityType', fn () => [
                'id' => $this->entityType?->id,
                'code' => $this->entityType?->code,
                'name' => $this->entityType?->name,
            ]),
            'address' => $this->address,
            'phone' => $this->phone,
            'fax' => $this->fax,
            'email' => $this->email,
            'director_person_id' => $this->director_person_id,
            'economic_director_person_id' => $this->economic_director_person_id,
            'parent_entity_id' => $this->parent_entity_id,
            'parent' => $this->whenLoaded('parent', fn () => $this->parent === null ? null : [
                'id' => $this->parent->id,
                'code' => $this->parent->code,
                'tax_id_number' => $this->parent->tax_id_number,
            ]),
            'social_purpose' => $this->social_purpose,
        ];
    }
}
