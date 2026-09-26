<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload for updating an agency (RF-CAT-003). PATCH semantics; the
 * code is immutable after creation.
 */
final class UpdateAgencyRequest extends FormRequest
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
            'code' => ['sometimes', 'string', 'alpha_num', 'max:10'],
            'name' => ['sometimes', 'string', 'max:120'],
            'province_id' => ['sometimes', 'integer'],
            'municipality_id' => ['sometimes', 'integer'],
            'agency_type_id' => ['sometimes', 'integer'],
        ];
    }
}
