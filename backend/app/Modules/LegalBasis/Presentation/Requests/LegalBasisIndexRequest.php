<?php

declare(strict_types=1);

namespace App\Modules\LegalBasis\Presentation\Requests;

use App\Modules\LegalBasis\Domain\LegalBasisStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query filters for the legal corpus search (RF-LEG-004): year, type
 * and issuing organization plus reference text, with the derived
 * status that feeds the selector of vigentes (RF-LEG-003).
 */
final class LegalBasisIndexRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:120'],
            'legal_basis_type_id' => ['nullable', 'integer', 'min:1'],
            'organization_id' => ['nullable', 'integer', 'min:1'],
            'year' => ['nullable', 'integer', 'min:1800', 'max:2200'],
            'status' => ['nullable', 'string', 'in:effective,derogated,future'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Search filters resolved for the repository, without pagination.
     *
     * @return array{q?: string, legal_basis_type_id?: int, organization_id?: int, year?: int, status?: LegalBasisStatus}
     */
    public function filters(): array
    {
        /** @var array{q?: string|null, legal_basis_type_id?: int|string|null, organization_id?: int|string|null, year?: int|string|null} $filters */
        $filters = $this->only(['q', 'legal_basis_type_id', 'organization_id', 'year']);

        /** @var array{q?: string, legal_basis_type_id?: int, organization_id?: int, year?: int} $filtered */
        $filtered = array_filter($filters, fn (int|string|null $value): bool => $value !== null && $value !== '');

        // The enum is resolved from the validated wire value, not
        // from the filtered array (whose PHPDoc already carries the
        // enum).
        $status = (string) $this->query('status', '');
        if ($status !== '') {
            $filtered['status'] = LegalBasisStatus::from($status);
        }

        return $filtered;
    }
}
