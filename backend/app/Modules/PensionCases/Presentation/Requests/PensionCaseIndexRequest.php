<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query filters of GET /pension-cases (RF-EXP-011 shape; the tuned
 * search and volume work land in S6). The status filter only accepts
 * the four normative values of section 2.4 so the service enum
 * resolution never sees garbage.
 *
 * SGP-35 (user correction): the listing is scoped to the office of
 * the AUTHENTICATED USER — office_id is NOT accepted in the query
 * anymore. A client that still sends the filter gets a 422 instead
 * of silently believing it drove the scope; the controller resolves
 * the actor's assignment through the Shared office port, exactly
 * like the store does with the payload field (user rule 0, ADR-33
 * sibling).
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
            // SGP-35: derived from the authenticated user, never sent.
            'office_id' => ['prohibited'],
            'applicant_person_id' => ['nullable', 'integer', 'min:1'],
            'number' => ['nullable', 'string', 'max:20'],
            'requested_from' => ['nullable', 'date_format:Y-m-d'],
            'requested_to' => ['nullable', 'date_format:Y-m-d', 'gte:requested_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Conversational rejection of the retired office filter: the
     * caller must never believe their value was honored.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'office_id.prohibited' => 'The office_id filter is not accepted: the listing is scoped to the office of the authenticated user.',
        ];
    }

    /**
     * Search filters resolved for the service, without pagination.
     *
     * @return array{status?: string, applicant_person_id?: int, number?: string, requested_from?: string, requested_to?: string}
     */
    public function filters(): array
    {
        /** @var array<string, string|null> $filters */
        $filters = $this->only(['status', 'applicant_person_id', 'number', 'requested_from', 'requested_to']);

        /** @var array{status?: string, applicant_person_id?: int, number?: string, requested_from?: string, requested_to?: string} $filtered */
        $filtered = array_filter(
            $filters,
            static fn (?string $value): bool => $value !== null && $value !== '',
        );

        return $filtered;
    }
}
