<?php

declare(strict_types=1);

namespace App\Modules\LegalBasis\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload for registering a legal basis (RF-LEG-002). Reference
 * existence and the RN-006 ordering live in the LegalBasisService,
 * which answers with per-field 422 errors; the year never travels on
 * the wire (derived from issue_date, H-11).
 */
final class StoreLegalBasisRequest extends FormRequest
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
            'legal_basis_type_id' => ['required', 'integer', 'min:1'],
            'number' => ['required', 'string', 'max:30'],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'derogation_date' => ['nullable', 'date_format:Y-m-d'],
            'issuing_organization_id' => ['required', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
