<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Wire contract of POST /pension-cases/{id}/work-cycles
 * (RF-EXP-004): non-negative integers, with UNSIGNED columns as the
 * last line.
 */
final class StoreWorkCycleRequest extends FormRequest
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
            'planned_days' => ['required', 'integer', 'min:0'],
            'actual_days' => ['required', 'integer', 'min:0'],
            'cycles_count' => ['required', 'integer', 'min:0'],
        ];
    }
}
