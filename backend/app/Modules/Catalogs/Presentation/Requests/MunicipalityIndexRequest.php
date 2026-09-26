<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query parameters for the municipalities listing (RF-CAT-002:
 * filterable by province, searchable by name).
 */
final class MunicipalityIndexRequest extends FormRequest
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
            'province_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string', 'in:name,code,id'],
            'order' => ['nullable', 'string', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
