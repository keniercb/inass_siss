<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Presentation\Resources;

use App\Modules\PensionCases\Infrastructure\Persistence\Models\WorkCycle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Work cycle projection (RF-EXP-004).
 *
 * @mixin WorkCycle
 */
#[OA\Schema(
    schema: 'WorkCycle',
    title: 'Ciclo de trabajo',
    description: 'Ciclo de trabajo declarado en el expediente (RF-EXP-004): días plan, días reales y cantidad, enteros no negativos que el cómputo de años de servicio consumirá según el régimen (RF-CAL-002).',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'pension_case_id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'planned_days', type: 'integer', example: 300),
        new OA\Property(property: 'actual_days', type: 'integer', example: 280),
        new OA\Property(property: 'cycles_count', type: 'integer', example: 1),
    ],
)]
final class WorkCycleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pension_case_id' => $this->pension_case_id,
            'planned_days' => (int) $this->planned_days,
            'actual_days' => (int) $this->actual_days,
            'cycles_count' => (int) $this->cycles_count,
        ];
    }
}
