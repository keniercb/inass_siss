<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query filters for the office search (RF-ENT-002/005): fragments
 * against the address plus exact type and geography filters.
 */
final class OfficeIndexRequest extends FormRequest
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
            'office_type_id' => ['nullable', 'integer', 'min:1'],
            'province_id' => ['nullable', 'integer', 'min:1'],
            'municipality_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{q?: string, office_type_id?: int, province_id?: int, municipality_id?: int}
     */
    public function filters(): array
    {
        /** @var array{q?: string|null, office_type_id?: int|string|null, province_id?: int|string|null, municipality_id?: int|string|null} $filters */
        $filters = $this->only(['q', 'office_type_id', 'province_id', 'municipality_id']);

        /** @var array{q?: string, office_type_id?: int, province_id?: int, municipality_id?: int} $filtered */
        $filtered = array_filter($filters, fn (string|int|null $value): bool => $value !== null && $value !== '');

        return $filtered;
    }
}
