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
    description: 'Oficina del Ministerio (RF-ENT-002). La pareja municipio-provincia es coherente (RN-04) y la jerarquía (parent) acíclica (RN-003). El detalle incluye el conteo de expedientes tramitados por la oficina y por su ámbito (RF-ENT-005, ADR-28).',
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
        new OA\Property(property: 'cases_count', type: 'integer', example: 3, description: 'Expedientes tramitados por la oficina (todo estado; ADR-28)'),
        new OA\Property(property: 'scope_cases_count', type: 'integer', example: 7, description: 'Expedientes en su ámbito: la oficina y sus subordinadas activas (RF-ENT-005, ADR-28)'),
    ],
)]
final class OfficeResource extends JsonResource
{
    /** @var array{cases_count: int, scope_cases_count: int}|null */
    private ?array $caseSummary = null;

    /**
     * Detail projection with the case counts (RF-ENT-005, ADR-28).
     *
     * A named constructor instead of a second constructor argument:
     * the inherited single-argument constructor is what
     * collection()/mapInto rely on — Laravel passes the collection
     * KEY as a second argument there, so widening the signature
     * breaks every paginated listing.
     *
     * @param  array{cases_count: int, scope_cases_count: int}  $caseSummary
     */
    public static function withCaseCountSummary(Office $office, array $caseSummary): self
    {
        $resource = new self($office);
        $resource->caseSummary = $caseSummary;

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
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

        if ($this->caseSummary !== null) {
            $data['cases_count'] = $this->caseSummary['cases_count'];
            $data['scope_cases_count'] = $this->caseSummary['scope_cases_count'];
        }

        return $data;
    }
}
