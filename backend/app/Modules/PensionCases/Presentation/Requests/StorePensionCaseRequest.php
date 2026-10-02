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
 * Task 35 (user correction over Task 34, English column name
 * since Task 36): filed_by_person_id — the
 * REGISTERED person who files or manages the case when it is not
 * the applicant themselves — travels as a nullable integer; the
 * registry probe (unknown or DEACTIVATED person answers 422)
 * lives in the service, exactly like the applicant and the rest
 * of the references: the FormRequest guards shape only.
 *
 * Task 37 (user correction, SGP-31): the internationalist flag —
 * REQUIRED boolean, parallel of rebel_army_member — plus the
 * promovente contact pair (phone, popular_council: nullable
 * strings with a ceiling). The nested service rows demand a
 * MANDATORY end_date; the strictly-posterior and no-overlap rules
 * are semantic probes of the service (the database CHECK is the
 * last line), exactly like the rest of the date rules.
 *
 * Task 38 (user correction, SGP-32): the fecha de desvinculación —
 * termination_date, an OPTIONAL date with the Y-m-d shape rule only
 * (no semantic probe: the user correction declares it plain
 * optional), exactly like requested_at's shape treatment.
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
            // Task 37: internationalist flag of the promovente —
            // required boolean, parallel of rebel_army_member.
            'internationalist' => ['required', 'boolean'],
            // Task 37: promovente contact pair — nullable free text.
            'phone' => ['nullable', 'string', 'max:30'],
            'popular_council' => ['nullable', 'string', 'max:120'],
            // Task 38: fecha de desvinculación — optional wire date,
            // shape rule only (no semantic probe).
            'termination_date' => ['nullable', 'date_format:Y-m-d'],
            // Task 35: reference to a REGISTERED person — the
            // existence/active probe is semantic (service), like
            // applicant_person_id.
            'filed_by_person_id' => ['nullable', 'integer', 'min:1'],
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
            // Task 37: the end date is MANDATORY — the open link no
            // longer exists; strictly-posterior and disjointness are
            // semantic probes of the service.
            'service_records.*.end_date' => ['required_with:service_records', 'date_format:Y-m-d'],
            'service_records.*.is_appendix' => ['nullable', 'boolean'],
            // Declaration form per row (Task 33; English name since
            // Task 36): the nested
            // payload accepts the same Documental|Testifical enum as
            // the individual endpoint — omitted rows keep the
            // Documental default, unknown values answer 422 instead
            // of being silently dropped.
            'service_records.*.declaration_form' => ['nullable', 'in:Documental,Testifical'],

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
