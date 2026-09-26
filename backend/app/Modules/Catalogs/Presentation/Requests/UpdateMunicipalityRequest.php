<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload for updating a municipality (RF-CAT-002). PATCH semantics;
 * the code is immutable after creation.
 */
final class UpdateMunicipalityRequest extends FormRequest
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
            'province_id' => ['sometimes', 'nullable', 'integer'],
            'code' => ['sometimes', 'string', 'alpha_num', 'max:4'],
            'name' => ['sometimes', 'string', 'max:80'],
        ];
    }
}
