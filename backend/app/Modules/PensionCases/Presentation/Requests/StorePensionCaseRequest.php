<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Wire contract of POST /pension-cases (RF-EXP-001, plan S5.2/S5.5,
 * user rules 0/1/4/5/ADR-33).
 *
 * Structural validation only: formats, types and the money shape
 * (RN-05: exact decimal string, never float). The semantic rules —
 * person alive and active, active office/entity/catalog references
 * (including the pension classifiers), the rebel army pair
 * coherence, no open case, year ceiling against the clock — live in
 * the service, so the same probes guard the nested subrecords of
 * the atomic creation.
 *
 * User rule 0 (ADR-33): office_id is NOT accepted in the payload —
 * the case assumes the office of the REGISTERING USER, resolved by
 * the controller through the Shared office port. A client that
 * still sends the field gets a 422 instead of silently believing
 * its value was honored.
 *
 * Task 34: persona_por — the free-text person who files or manages
 * the case when it is not the applicant themselves — is an optional
 * passthrough: nullable string capped at the VARCHAR(120) of the
 * schema, with no semantic rule (the case can be filed by the
 * applicant in person).
 */
final class StorePensionCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $money = ['regex:/^\d{1,10}(\.\d{1,2})?$/'];

        return [
            'applicant_person_id' => ['required', 'integer', 'min:1'],
            // Rule 0: derived from the authenticated user, never sent.
            'office_id' => ['prohibited'],
            'employer_entity_id' => ['required', 'integer', 'min:1'],
            'position_id' => ['required', 'integer', 'min:1'],
            'occupational_category_id' => ['required', 'integer', 'min:1'],
            'educational_level_id' => ['required', 'integer', 'min:1'],
            'scientific_category_id' => ['required', 'integer', 'min:1'],
            // Rule 4: pension classifiers + rebel army pair.
            'pension_type_id' => ['required', 'integer', 'min:1'],
            'pension_regime_id' => ['required', 'integer', 'min:1'],
            'rebel_army_member' => ['required', 'boolean'],
            'rebel_army_join_date' => [
                'nullable',
                'date_format:Y-m-d',
                // Required when the membership is true…
                'required_if:rebel_army_member,1,true',
                // …and rejected when it is false.
                'prohibited_unless:rebel_army_member,1,true',
            ],
            // Task 34: free-text passthrough, VARCHAR(120).
            'persona_por' => ['nullable', 'string', 'max:120'],
            // RN-005: money travels as an exact decimal string.
            'last_salary' => ['required', ...$money],
            'requested_at' => ['nullable', 'date_format:Y-m-d'],

            // Rule 1: at most FIFTEEN salary rows per payload — the
            // service guards the individual highs with the same
            // domain constant.
            'salary_records' => ['nullable', 'array', 'max:15'],
            'salary_records.*.year' => ['required_with:salary_records', 'integer', 'min:1950', 'max:2150'],
            'salary_records.*.earned_salary' => ['required_with:salary_records', ...$money],

            'service_records' => ['nullable', 'array', 'max:200'],
            'service_records.*.entity_id' => ['required_with:service_records', 'integer', 'min:1'],
            'service_records.*.start_date' => ['required_with:service_records', 'date_format:Y-m-d'],
            'service_records.*.end_date' => ['nullable', 'date_format:Y-m-d'],
            'service_records.*.is_appendix' => ['nullable', 'boolean'],
            // Forma de declaración per row (Task 33): the nested
            // payload accepts the same Documental|Testifical enum as
            // the individual endpoint — omitted rows keep the
            // Documental default, unknown values answer 422 instead
            // of being silently dropped.
            'service_records.*.forma_declaracion' => ['nullable', 'in:Documental,Testifical'],

            'work_cycles' => ['nullable', 'array', 'max:100'],
            'work_cycles.*.planned_days' => ['required_with:work_cycles', 'integer', 'min:0'],
            'work_cycles.*.actual_days' => ['required_with:work_cycles', 'integer', 'min:0'],
            'work_cycles.*.cycles_count' => ['required_with:work_cycles', 'integer', 'min:0'],

            // Rule 5: income concepts travel as nested subrecords.
            'income_concept_records' => ['nullable', 'array', 'max:200'],
            'income_concept_records.*.income_concept_id' => ['required_with:income_concept_records', 'integer', 'min:1'],
            'income_concept_records.*.amount' => ['required_with:income_concept_records', ...$money],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'office_id.prohibited' => 'The office_id field is not accepted: the case assumes the office of the registering user.',
        ];
    }
}
