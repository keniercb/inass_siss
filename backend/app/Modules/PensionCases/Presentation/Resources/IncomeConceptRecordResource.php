<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Presentation\Resources;

use App\Modules\PensionCases\Infrastructure\Persistence\Models\IncomeConceptRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Income concept row projection (user rule 5).
 *
 * @mixin IncomeConceptRecord
 */
#[OA\Schema(
    schema: 'IncomeConceptRecord',
    title: 'Concepto de ingreso del expediente',
    description: 'Valor declarado de un concepto de ingreso del expediente (regla de usuario 5): el par expediente-concepto es único y el valor es DECIMAL(12,2) no negativo (RN-005).',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'pension_case_id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'income_concept_id', type: 'integer', format: 'int64', example: 3, description: 'Concepto del catálogo (Salario en divisas, Antigüedad…)'),
        new OA\Property(property: 'amount', type: 'string', example: '150.00', description: 'Importe exacto con dos decimales (RN-005)'),
    ],
)]
final class IncomeConceptRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pension_case_id' => $this->pension_case_id,
            'income_concept_id' => $this->income_concept_id,
            'amount' => (string) $this->amount,
        ];
    }
}
