<?php

declare(strict_types=1);

namespace App\Modules\Settings\Presentation\Resources;

use App\Modules\Settings\Infrastructure\Persistence\Models\GeneralSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * @mixin GeneralSetting
 */
#[OA\Schema(
    schema: 'GeneralSettingVersion',
    title: 'Versión de configuración general',
    description: 'Parámetros de cálculo de una vigencia (RF-CAT-005, RN-007). Las versiones son inmutables: la corrección crea una nueva vigencia. effective_to es derivado (día anterior a la siguiente vigencia; null en la más reciente) y nunca se almacena.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 3),
        new OA\Property(property: 'min_work_years', type: 'integer', example: 30, description: 'Años mínimos de trabajo'),
        new OA\Property(property: 'min_age_men', type: 'integer', example: 60, description: 'Edad mínima hombres'),
        new OA\Property(property: 'min_age_women', type: 'integer', example: 55, description: 'Edad mínima mujeres'),
        new OA\Property(property: 'base_calc_percent', type: 'integer', example: 50, minimum: 0, maximum: 100, description: 'Por ciento de cálculo base'),
        new OA\Property(property: 'max_calc_percent', type: 'integer', example: 90, minimum: 0, maximum: 100, description: 'Por ciento máximo (mayor o igual que base)'),
        new OA\Property(property: 'annual_increase_percent', type: 'integer', example: 1, minimum: 0, maximum: 100, description: 'Incremento anual por excedencia'),
        new OA\Property(property: 'effective_from', type: 'string', format: 'date', example: '2026-06-01', description: 'Entrada en vigor (única por fecha, RN-007)'),
        new OA\Property(property: 'effective_to', type: 'string', format: 'date', nullable: true, example: null, description: 'Derivado: día anterior a la siguiente vigencia; null en la vigencia más reciente'),
        new OA\Property(property: 'created_by', type: 'integer', format: 'int64', nullable: true, description: 'Usuario autor de la versión (ADR-14)'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
final class GeneralSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'min_work_years' => $this->min_work_years,
            'min_age_men' => $this->min_age_men,
            'min_age_women' => $this->min_age_women,
            'base_calc_percent' => $this->base_calc_percent,
            'max_calc_percent' => $this->max_calc_percent,
            'annual_increase_percent' => $this->annual_increase_percent,
            'effective_from' => $this->effective_from->format('Y-m-d'),
            'effective_to' => $this->effective_to,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
