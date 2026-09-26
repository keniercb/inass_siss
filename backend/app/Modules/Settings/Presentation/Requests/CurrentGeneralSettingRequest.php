<?php

declare(strict_types=1);

namespace App\Modules\Settings\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query parameters for resolving the version in force
 * (/general-settings/current): the optional `at` date exercises the
 * domain resolution at any point of the timeline (RN-007); without
 * it the Clock port resolves "now".
 */
final class CurrentGeneralSettingRequest extends FormRequest
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
            'at' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
