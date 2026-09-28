<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Presentation\Resources;

use App\Modules\PensionCases\Infrastructure\Persistence\Models\SalaryRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Salary row projection (RF-EXP-002).
 *
 * @mixin SalaryRecord
 */
#[OA\Schema(
    schema: 'SalaryRecord',
    title: 'Registro de salario',
    description: 'Salario devengado en un año del expediente (RF-EXP-002). El par expediente-año es único; earned_salary es DECIMAL(12,2) no negativo (RN-005).',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'pension_case_id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'year', type: 'integer', example: 2024, description: 'Año devengado (1950…año actual+1)'),
        new OA\Property(property: 'earned_salary', type: 'string', example: '4800.00', description: 'Importe exacto con dos decimales (RN-005)'),
    ],
)]
final class SalaryRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pension_case_id' => $this->pension_case_id,
            'year' => (int) $this->year,
            'earned_salary' => (string) $this->earned_salary,
        ];
    }
}
