<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Wire contract of POST /pension-cases/{id}/salary-records
 * (RF-EXP-002). The (case, year) uniqueness and the year ceiling
 * against the clock are semantic rules answered by the service; this
 * class pins formats only.
 */
final class StoreSalaryRecordRequest extends FormRequest
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
            'year' => ['required', 'integer', 'min:1950', 'max:2150'],
            // RN-005: exact decimal string, never float.
            'earned_salary' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
        ];
    }
}
