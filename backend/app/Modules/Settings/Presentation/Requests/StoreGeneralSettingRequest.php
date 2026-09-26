<?php

declare(strict_types=1);

namespace App\Modules\Settings\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload for creating a settings version (RF-CAT-005).
 *
 * Ranges follow the data model (section 5.3): parameters are
 * unsigned integers, percentages live in 0-100 and the maximum
 * percent never drops below the base percent. The overlap rule of
 * RN-007 (unique effective_from) is probed by the service to answer
 * a semantic 422 and backed by the database UNIQUE constraint.
 */
final class StoreGeneralSettingRequest extends FormRequest
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
        return [
            'min_work_years' => ['required', 'integer', 'min:0', 'max:100'],
            'min_age_men' => ['required', 'integer', 'min:0', 'max:120'],
            'min_age_women' => ['required', 'integer', 'min:0', 'max:120'],
            'base_calc_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'max_calc_percent' => ['required', 'integer', 'min:0', 'max:100', 'gte:base_calc_percent'],
            'annual_increase_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
