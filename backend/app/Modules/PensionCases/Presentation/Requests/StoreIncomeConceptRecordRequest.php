<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Wire contract of POST /pension-cases/{id}/income-concept-records
 * (user rule 5).
 *
 * Structural validation only: the concept reference shape, the
 * money shape (RN-05: exact decimal string, never float) and —
 * since Task 42 (user correction, SGP-36) — the applied percent
 * shape: REQUIRED Double materialized as an exact decimal string
 * with range 0-100 and at most two decimals. The semantic rules —
 * concept exists and stays active, the (case, concept) pair still
 * undeclared, editable state — live in the service behind the port.
 */
final class StoreIncomeConceptRecordRequest extends FormRequest
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
            'income_concept_id' => ['required', 'integer', 'min:1'],
            // RN-005: money travels as an exact decimal string.
            'amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            // Task 42: percent to apply — same doctrine as the money
            // shape: exact decimal string, range 0-100, at most two
            // decimals (never a binary float).
            'applied_percent' => [
                'required',
                'numeric',
                'between:0,100',
                'regex:/^\d{1,3}(\.\d{1,2})?$/',
            ],
        ];
    }
}
