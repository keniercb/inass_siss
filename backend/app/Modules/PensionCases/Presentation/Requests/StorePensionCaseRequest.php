<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Wire contract of POST /pension-cases (RF-EXP-001, plan S5.2/S5.5).
 *
 * Structural validation only: formats, types and the money shape
 * (RN-05: exact decimal string, never float). The semantic rules —
 * person alive and active, active office/entity/catalog references,
 * no open case, year ceiling against the clock — live in the
 * service, so the same probes guard the nested subrecords of the
 * atomic creation.
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
            'office_id' => ['required', 'integer', 'min:1'],
            'employer_entity_id' => ['required', 'integer', 'min:1'],
            'position_id' => ['required', 'integer', 'min:1'],
            'occupational_category_id' => ['required', 'integer', 'min:1'],
            'educational_level_id' => ['required', 'integer', 'min:1'],
            'scientific_category_id' => ['required', 'integer', 'min:1'],
            // RN-005: money travels as an exact decimal string.
            'last_salary' => ['required', ...$money],
            'requested_at' => ['nullable', 'date_format:Y-m-d'],

            'salary_records' => ['nullable', 'array', 'max:200'],
            'salary_records.*.year' => ['required_with:salary_records', 'integer', 'min:1950', 'max:2150'],
            'salary_records.*.earned_salary' => ['required_with:salary_records', ...$money],

            'service_records' => ['nullable', 'array', 'max:200'],
            'service_records.*.entity_id' => ['required_with:service_records', 'integer', 'min:1'],
            'service_records.*.start_date' => ['required_with:service_records', 'date_format:Y-m-d'],
            'service_records.*.end_date' => ['nullable', 'date_format:Y-m-d'],
            'service_records.*.is_appendix' => ['nullable', 'boolean'],

            'work_cycles' => ['nullable', 'array', 'max:100'],
            'work_cycles.*.planned_days' => ['required_with:work_cycles', 'integer', 'min:0'],
            'work_cycles.*.actual_days' => ['required_with:work_cycles', 'integer', 'min:0'],
            'work_cycles.*.cycles_count' => ['required_with:work_cycles', 'integer', 'min:0'],
        ];
    }
}
