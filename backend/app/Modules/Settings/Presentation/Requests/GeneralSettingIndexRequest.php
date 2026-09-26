<?php

declare(strict_types=1);

namespace App\Modules\Settings\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query parameters for the settings versions listing (RF-CAT-006
 * pagination conventions).
 */
final class GeneralSettingIndexRequest extends FormRequest
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
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
