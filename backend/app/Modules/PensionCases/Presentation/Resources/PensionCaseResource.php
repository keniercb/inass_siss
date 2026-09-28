<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Presentation\Resources;

use App\Modules\PensionCases\Infrastructure\Persistence\Models\PensionCase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Pension case projection (RF-EXP-001..004): the aggregate with its
 * subrecords. The advisory analysis (missing salary years,
 * overlapping and open services) travels as a sibling `warnings`
 * object of the envelope — never inside data — because it is
 * derived evidence for the specialist, not case state. The decision
 * fields (approval_legal_basis_id, decision_notes, decided_at,
 * decided_by, computed_amount) stay null until the S6 transitions
 * write them.
 *
 * @mixin PensionCase
 */
#[OA\Schema(
    schema: 'PensionCase',
    title: 'Expediente de pensión',
    description: 'Expediente de pensión (RF-EXP-001): número secuencial único (RN-009), estado de la sección 2.4 y subregistros declarados. Los campos de decisión quedan null hasta las transiciones de S6.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'number', type: 'string', example: '1', description: 'Número del expediente (secuencia pension_case, único)'),
        new OA\Property(property: 'requested_at', type: 'string', format: 'date', example: '2026-09-28'),
        new OA\Property(property: 'status', type: 'string', enum: ['submitted', 'under_review', 'approved', 'rejected'], example: 'submitted', description: 'Estado normativo de la sección 2.4'),
        new OA\Property(property: 'applicant_person_id', type: 'integer', format: 'int64', example: 7),
        new OA\Property(property: 'office_id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'employer_entity_id', type: 'integer', format: 'int64', example: 3),
        new OA\Property(property: 'position_id', type: 'integer', format: 'int64', example: 2),
        new OA\Property(property: 'occupational_category_id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'educational_level_id', type: 'integer', format: 'int64', example: 4),
        new OA\Property(property: 'scientific_category_id', type: 'integer', format: 'int64', example: 2),
        new OA\Property(property: 'last_salary', type: 'string', example: '5000.00', description: 'Último salario, DECIMAL(12,2) no negativo (RN-005)'),
        new OA\Property(property: 'approval_legal_basis_id', type: 'integer', format: 'int64', nullable: true, example: null, description: 'Resolución aprobatoria (H-05); la fija la aprobación de S6'),
        new OA\Property(property: 'decision_notes', type: 'string', nullable: true, example: null, description: 'Nota de resolución o motivo de denegación (S6)'),
        new OA\Property(property: 'decided_at', type: 'string', format: 'date-time', nullable: true, example: null),
        new OA\Property(property: 'decided_by', type: 'integer', format: 'int64', nullable: true, example: null),
        new OA\Property(property: 'computed_amount', type: 'string', nullable: true, example: null, description: 'Cuantía congelada al aprobar (S6)'),
        new OA\Property(property: 'calculation_setting_id', type: 'integer', format: 'int64', nullable: true, example: null, description: 'Versión de parámetros usada (RF-CAL-008, S6)'),
        new OA\Property(property: 'salary_records', type: 'array', items: new OA\Items(ref: '#/components/schemas/SalaryRecord')),
        new OA\Property(property: 'service_records', type: 'array', items: new OA\Items(ref: '#/components/schemas/ServiceRecord')),
        new OA\Property(property: 'work_cycles', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkCycle')),
        new OA\Property(property: 'applicant', nullable: true, properties: [
            new OA\Property(property: 'id', type: 'integer', format: 'int64'),
            new OA\Property(property: 'identity_number', type: 'string'),
            new OA\Property(property: 'first_name', type: 'string'),
            new OA\Property(property: 'first_surname', type: 'string'),
        ], type: 'object', description: 'Resumen del proponente para desambiguar'),
    ],
)]
final class PensionCaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'requested_at' => $this->requested_at->format('Y-m-d'),
            'status' => $this->status->value,
            'applicant_person_id' => $this->applicant_person_id,
            'office_id' => $this->office_id,
            'employer_entity_id' => $this->employer_entity_id,
            'position_id' => $this->position_id,
            'occupational_category_id' => $this->occupational_category_id,
            'educational_level_id' => $this->educational_level_id,
            'scientific_category_id' => $this->scientific_category_id,
            'last_salary' => (string) $this->last_salary,
            'approval_legal_basis_id' => $this->approval_legal_basis_id,
            'decision_notes' => $this->decision_notes,
            'decided_at' => $this->decided_at?->format('Y-m-d H:i:s'),
            'decided_by' => $this->decided_by,
            'computed_amount' => $this->computed_amount !== null ? (string) $this->computed_amount : null,
            'calculation_setting_id' => $this->calculation_setting_id,
            'salary_records' => SalaryRecordResource::collection($this->whenLoaded('salaryRecords')),
            'service_records' => ServiceRecordResource::collection($this->whenLoaded('serviceRecords')),
            'work_cycles' => WorkCycleResource::collection($this->whenLoaded('workCycles')),
            'applicant' => $this->whenLoaded('applicant', [
                'id' => $this->applicant?->id,
                'identity_number' => $this->applicant?->identity_number,
                'first_name' => $this->applicant?->first_name,
                'first_surname' => $this->applicant?->first_surname,
            ]),
        ];
    }
}
