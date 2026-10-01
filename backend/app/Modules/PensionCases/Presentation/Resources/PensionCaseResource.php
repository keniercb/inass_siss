<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Presentation\Resources;

use App\Modules\PensionCases\Infrastructure\Persistence\Models\PensionCase;
use App\Modules\People\Presentation\Resources\PersonResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Pension case projection (RF-EXP-001..004 + user rules 0-5): the
 * aggregate with its subrecords. The applicant travels as the FULL
 * Person projection (user rule 3 — reused from the People module's
 * resource so the promovente is never a summary that drifts from
 * the people surface). The advisory analysis (missing salary years,
 * overlapping and open services) travels as a sibling `warnings`
 * object of the envelope — never inside data — because it is
 * derived evidence for the specialist, not case state. The decision
 * fields (approval_legal_basis_id, decision_notes, decided_at,
 * decided_by, computed_amount) stay null until the S6 transitions
 * write them. The persona por (Task 35, user correction over Task
 * 34) is a REFERENCE to a registered person: persona_por_id plus
 * the FULL Person projection of the filer under persona_por — the
 * same shape as the applicant (user rule 3, reused from the People
 * module's resource so the projection never drifts).
 *
 * @mixin PensionCase
 */
#[OA\Schema(
    schema: 'PensionCase',
    title: 'Expediente de pensión',
    description: 'Expediente de pensión (RF-EXP-001): número compuesto PPMMAACCCCC — provincia y municipio de la oficina registrante, últimos dos dígitos del año en curso y consecutivo por año/provincia/municipio, once dígitos contiguos (regla de usuario 2/ADR-34) —, estado de la sección 2.4, clasificación de pensión y par de Ejército Rebelde (regla 4) y subregistros declarados. Los campos de decisión quedan null hasta las transiciones de S6.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'number', type: 'string', example: '11032600001', description: 'Número del expediente: PPMMAACCCCC (provincia-municipio-año-consecutivo territorial), único'),
        new OA\Property(property: 'requested_at', type: 'string', format: 'date', example: '2026-09-30'),
        new OA\Property(property: 'status', type: 'string', enum: ['submitted', 'under_review', 'approved', 'rejected'], example: 'submitted', description: 'Estado normativo de la sección 2.4'),
        new OA\Property(property: 'applicant_person_id', type: 'integer', format: 'int64', example: 7),
        new OA\Property(property: 'office_id', type: 'integer', format: 'int64', example: 1, description: 'Oficina del usuario que registró el expediente (regla 0/ADR-33)'),
        new OA\Property(property: 'employer_entity_id', type: 'integer', format: 'int64', example: 3),
        new OA\Property(property: 'position_id', type: 'integer', format: 'int64', example: 2),
        new OA\Property(property: 'occupational_category_id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'educational_level_id', type: 'integer', format: 'int64', example: 4),
        new OA\Property(property: 'scientific_category_id', type: 'integer', format: 'int64', example: 2),
        new OA\Property(property: 'pension_type_id', type: 'integer', format: 'int64', example: 1, description: 'Tipo de pensión del catálogo (regla 4)'),
        new OA\Property(property: 'pension_regime_id', type: 'integer', format: 'int64', example: 1, description: 'Régimen de pensión del catálogo (regla 4)'),
        new OA\Property(property: 'last_salary', type: 'string', example: '5000.00', description: 'Último salario, DECIMAL(12,2) no negativo (RN-005)'),
        new OA\Property(property: 'rebel_army_member', type: 'boolean', example: false, description: 'Pertenece al Ejército Rebelde (regla 4)'),
        new OA\Property(property: 'rebel_army_join_date', type: 'string', format: 'date', nullable: true, example: null, description: 'Fecha de alta en el Ejército Rebelde: obligatoria si rebel_army_member es true'),
        new OA\Property(property: 'persona_por_id', type: 'integer', format: 'int64', nullable: true, example: 12, description: 'Persona por (Task 35, corrección de usuario): id de la persona REGISTRADA que presenta o gestiona el expediente cuando no es el propio proponente; 422 si no existe o está desactivada, NULL si se omite'),
        new OA\Property(property: 'persona_por', nullable: true, allOf: [new OA\Schema(ref: '#/components/schemas/Person')], description: 'Proyección COMPLETA de la persona por (Task 35): misma forma que applicant'),
        new OA\Property(property: 'approval_legal_basis_id', type: 'integer', format: 'int64', nullable: true, example: null, description: 'Resolución aprobatoria (H-05); la fija la aprobación de S6'),
        new OA\Property(property: 'decision_notes', type: 'string', nullable: true, example: null, description: 'Nota de resolución o motivo de denegación (S6)'),
        new OA\Property(property: 'decided_at', type: 'string', format: 'date-time', nullable: true, example: null),
        new OA\Property(property: 'decided_by', type: 'integer', format: 'int64', nullable: true, example: null),
        new OA\Property(property: 'computed_amount', type: 'string', nullable: true, example: null, description: 'Cuantía congelada al aprobar (S6)'),
        new OA\Property(property: 'calculation_setting_id', type: 'integer', format: 'int64', nullable: true, example: null, description: 'Versión de parámetros usada (RF-CAL-008, S6)'),
        new OA\Property(property: 'salary_records', type: 'array', description: 'Serie salarial: máximo 15 filas (regla 1)', items: new OA\Items(ref: '#/components/schemas/SalaryRecord')),
        new OA\Property(property: 'service_records', type: 'array', items: new OA\Items(ref: '#/components/schemas/ServiceRecord')),
        new OA\Property(property: 'work_cycles', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkCycle')),
        new OA\Property(property: 'income_concept_records', type: 'array', description: 'Conceptos de ingreso declarados (regla 5)', items: new OA\Items(ref: '#/components/schemas/IncomeConceptRecord')),
        new OA\Property(property: 'applicant', nullable: true, allOf: [new OA\Schema(ref: '#/components/schemas/Person')], description: 'Proyección COMPLETA del promovente (regla 3)'),
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
            'pension_type_id' => $this->pension_type_id,
            'pension_regime_id' => $this->pension_regime_id,
            'last_salary' => (string) $this->last_salary,
            'rebel_army_member' => $this->rebel_army_member,
            'rebel_army_join_date' => $this->rebel_army_join_date?->format('Y-m-d'),
            'persona_por_id' => $this->persona_por_id,
            'approval_legal_basis_id' => $this->approval_legal_basis_id,
            'decision_notes' => $this->decision_notes,
            'decided_at' => $this->decided_at?->format('Y-m-d H:i:s'),
            'decided_by' => $this->decided_by,
            'computed_amount' => $this->computed_amount !== null ? (string) $this->computed_amount : null,
            'calculation_setting_id' => $this->calculation_setting_id,
            'salary_records' => SalaryRecordResource::collection($this->whenLoaded('salaryRecords')),
            'service_records' => ServiceRecordResource::collection($this->whenLoaded('serviceRecords')),
            'work_cycles' => WorkCycleResource::collection($this->whenLoaded('workCycles')),
            'income_concept_records' => IncomeConceptRecordResource::collection($this->whenLoaded('incomeConceptRecords')),
            'applicant' => $this->whenLoaded('applicant', fn () => new PersonResource($this->applicant)),
            // Task 35: full Person projection of the filer — null
            // (never a broken resource) when the reference is NULL.
            'persona_por' => $this->whenLoaded(
                'personaPor',
                fn () => $this->personaPor === null ? null : new PersonResource($this->personaPor),
            ),
        ];
    }
}
