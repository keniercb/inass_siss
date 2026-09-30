<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Wire contract of POST /pension-cases/{id}/income-concept-records
 * (user rule 5).
 *
 * Structural validation only: the concept reference shape and the
 * money shape (RN-05: exact decimal string, never float). The
 * semantic rules — concept exists and stays active, the (case,
 * concept) pair still undeclared, editable state — live in the
 * service behind the port.
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
        ];
    }
}
