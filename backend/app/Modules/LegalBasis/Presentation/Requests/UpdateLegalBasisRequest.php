<?php

declare(strict_types=1);

namespace App\Modules\LegalBasis\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload for editing a legal basis (RF-LEG-002). PATCH semantics:
 * the tern identity (type, number, issue_date) is immutable — the
 * service answers 422 when it changes — and the resulting dates
 * revalidate the RN-006 ordering. Derogation is set, corrected or
 * cleared here: an auditable date edit, never a destructive action.
 */
final class UpdateLegalBasisRequest extends FormRequest
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
            'legal_basis_type_id' => ['sometimes', 'integer', 'min:1'],
            'number' => ['sometimes', 'string', 'max:30'],
            'issue_date' => ['sometimes', 'date_format:Y-m-d'],
            'effective_date' => ['sometimes', 'date_format:Y-m-d'],
            'derogation_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'issuing_organization_id' => ['sometimes', 'integer', 'min:1'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
