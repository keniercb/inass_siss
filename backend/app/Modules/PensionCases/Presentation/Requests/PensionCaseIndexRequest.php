<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query filters of GET /pension-cases (RF-EXP-011 shape; the tuned
 * search and volume work land in S6). The status filter only accepts
 * the four normative values of section 2.4 so the service enum
 * resolution never sees garbage.
 */
final class PensionCaseIndexRequest extends FormRequest
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
            'status' => ['nullable', 'string', 'in:submitted,under_review,approved,rejected'],
            'office_id' => ['nullable', 'integer', 'min:1'],
            'applicant_person_id' => ['nullable', 'integer', 'min:1'],
            'number' => ['nullable', 'string', 'max:20'],
            'requested_from' => ['nullable', 'date_format:Y-m-d'],
            'requested_to' => ['nullable', 'date_format:Y-m-d', 'gte:requested_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Search filters resolved for the service, without pagination.
     *
     * @return array{status?: string, office_id?: int, applicant_person_id?: int, number?: string, requested_from?: string, requested_to?: string}
     */
    public function filters(): array
    {
        /** @var array<string, string|null> $filters */
        $filters = $this->only(['status', 'office_id', 'applicant_person_id', 'number', 'requested_from', 'requested_to']);

        /** @var array{status?: string, office_id?: int, applicant_person_id?: int, number?: string, requested_from?: string, requested_to?: string} $filtered */
        $filtered = array_filter(
            $filters,
            static fn (?string $value): bool => $value !== null && $value !== '',
        );

        return $filtered;
    }
}
