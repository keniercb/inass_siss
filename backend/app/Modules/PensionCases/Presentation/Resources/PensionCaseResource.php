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
 * write them. The filer (Task 35, user correction over Task
 * 34; English column names since Task 36) is a REFERENCE to a
 * registered person: filed_by_person_id plus
 * the FULL Person projection of the filer under filed_by — the
 * same shape as the applicant (user rule 3, reused from the People
 * module's resource so the projection never drifts). Since the
 * Task 37 user correction (SGP-31) the case also answers the
 * internationalist flag of the promovente (beside the rebel army
 * pair) and the promovente contact pair (phone, popular_council) —
 * and the service periods travel closed and disjoint, so the
 * warnings envelope only carries the salary analysis. Since the
 * Task 38 user correction (SGP-32) the case also answers the
 * promovente's fecha de desvinculación — termination_date, an
 * optional date serialized as Y-m-d and null when absent.
 *
 * @mixin PensionCase
 */
#[OA\Schema(
    schema: 'PensionCase',
    title: 'Expediente de pensión',
    description: 'Expediente de pensión (RF-EXP-001): número compuesto PPMMAACCCCC — provincia y municipio de la oficina registrante, últimos dos dígitos del año en curso y consecutivo por año/provincia/municipio, once dígitos contiguos (regla de usuario 2/ADR-34) —, estado de la sección 2.4, clasificación de pensión, par de Ejército Rebelde y marca de internacionalista (Task 37), contacto del promovente (teléfono y consejo popular, Task 37), fecha de desvinculación del promovente (Task 38) y subregistros declarados — con los períodos de servicio cerrados y disjuntos. Los campos de decisión quedan null hasta las transiciones de S6.',
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
        new OA\Property(property: 'internationalist', type: 'boolean', example: true, description: 'Internacionalista (Task 37, corrección de usuario): el promovente cumplió misión internacionalista — obligatorio en el alta, paralelo de rebel_army_member'),
        new OA\Property(property: 'filed_by_person_id', type: 'integer', format: 'int64', nullable: true, example: 12, description: 'Persona por (Task 35, corrección de usuario; columna inglesa desde Task 36): id de la persona REGISTRADA que presenta o gestiona el expediente cuando no es el propio proponente; 422 si no existe o está desactivada, NULL si se omite'),
        new OA\Property(property: 'phone', type: 'string', nullable: true, maxLength: 30, example: '+53 5 555 1234', description: 'Teléfono de contacto del promovente (Task 37): texto libre opcional'),
        new OA\Property(property: 'popular_council', type: 'string', nullable: true, maxLength: 120, example: 'Consejo Popular Playa', description: 'Consejo popular del promovente (Task 37): división territorial cubana, texto libre opcional'),
        new OA\Property(property: 'termination_date', type: 'string', format: 'date', nullable: true, example: '2025-07-31', description: 'Fecha de desvinculación del promovente (Task 38, corrección de usuario): opcional, Y-m-d; la omisión persiste null'),
        new OA\Property(property: 'filed_by', nullable: true, allOf: [new OA\Schema(ref: '#/components/schemas/Person')], description: 'Proyección COMPLETA de la persona por (Task 35): misma forma que applicant'),
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
            'internationalist' => (bool) $this->internationalist,
            'filed_by_person_id' => $this->filed_by_person_id,
            'phone' => $this->phone,
            'popular_council' => $this->popular_council,
            'termination_date' => $this->termination_date?->format('Y-m-d'),
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
            'filed_by' => $this->whenLoaded(
                'filedBy',
                fn () => $this->filedBy === null ? null : new PersonResource($this->filedBy),
            ),
        ];
    }
}
