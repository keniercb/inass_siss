<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload for editing an office (RF-ENT-002). PATCH semantics: every
 * field is optional and the resulting state is revalidated by the
 * service, including the acyclicity of a new parent (RN-003).
 */
final class UpdateOfficeRequest extends FormRequest
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
            'office_type_id' => ['sometimes', 'integer', 'min:1'],
            'province_id' => ['sometimes', 'integer', 'min:1'],
            'municipality_id' => ['sometimes', 'integer', 'min:1'],
            'address' => ['sometimes', 'string', 'max:255'],
            'parent_office_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
