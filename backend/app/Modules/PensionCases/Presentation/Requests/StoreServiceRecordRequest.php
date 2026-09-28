<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Wire contract of POST /pension-cases/{id}/service-records
 * (RF-EXP-003). Date order is a semantic probe in the service (the
 * database CHECK is the last line) and overlaps are advertised, not
 * rejected — so this class pins formats and types only.
 */
final class StoreServiceRecordRequest extends FormRequest
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
            'entity_id' => ['required', 'integer', 'min:1'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            // Coletilla: recognized additional service.
            'is_appendix' => ['nullable', 'boolean'],
        ];
    }
}
