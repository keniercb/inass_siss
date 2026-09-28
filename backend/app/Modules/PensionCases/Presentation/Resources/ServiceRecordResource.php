<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Presentation\Resources;

use App\Modules\PensionCases\Infrastructure\Persistence\Models\ServiceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Work service projection (RF-EXP-003). end_date null means the
 * employment link is still open; overlaps and open links are
 * advertised in the case-level warnings, never blocked here.
 *
 * @mixin ServiceRecord
 */
#[OA\Schema(
    schema: 'ServiceRecord',
    title: 'Registro de servicio',
    description: 'Vínculo laboral declarado en el expediente (RF-EXP-003). end_date null = vínculo vigente; is_appendix marca la coletilla (servicio reconocido adicional). El orden end ≥ start está respaldado por CHECK y los solapamientos se detectan y advierten.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'pension_case_id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'entity_id', type: 'integer', format: 'int64', example: 3),
        new OA\Property(property: 'start_date', type: 'string', format: 'date', example: '2000-01-01'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true, example: null, description: 'null = vínculo vigente'),
        new OA\Property(property: 'is_appendix', type: 'boolean', example: false, description: 'Coletilla: servicio reconocido adicional'),
    ],
)]
final class ServiceRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pension_case_id' => $this->pension_case_id,
            'entity_id' => $this->entity_id,
            'start_date' => $this->start_date->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'is_appendix' => (bool) $this->is_appendix,
        ];
    }
}
