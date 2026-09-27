<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query filters for the entity search (RF-ENT-005): fragments against
 * code, NIT and social purpose plus exact reference filters. The
 * repository owns the semantics; this class only pins the wire
 * contract.
 */
final class EntityIndexRequest extends FormRequest
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
            'organization_id' => ['nullable', 'integer', 'min:1'],
            'province_id' => ['nullable', 'integer', 'min:1'],
            'municipality_id' => ['nullable', 'integer', 'min:1'],
            'entity_type_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Search filters resolved for the repository, without pagination.
     *
     * @return array{q?: string, organization_id?: int, province_id?: int, municipality_id?: int, entity_type_id?: int}
     */
    public function filters(): array
    {
        /** @var array{q?: string|null, organization_id?: int|string|null, province_id?: int|string|null, municipality_id?: int|string|null, entity_type_id?: int|string|null} $filters */
        $filters = $this->only(['q', 'organization_id', 'province_id', 'municipality_id', 'entity_type_id']);

        /** @var array{q?: string, organization_id?: int, province_id?: int, municipality_id?: int, entity_type_id?: int} $filtered */
        $filtered = array_filter($filters, fn (string|int|null $value): bool => $value !== null && $value !== '');

        return $filtered;
    }
}
