<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload for editing an entity (RF-ENT-001). PATCH semantics: every
 * field is optional, code and tax_id_number are immutable (the
 * service answers 422 when they change) and the resulting state is
 * revalidated by the service.
 */
final class UpdateEntityRequest extends FormRequest
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
            'code' => ['sometimes', 'string', 'max:15'],
            'tax_id_number' => ['sometimes', 'string', 'max:20'],
            'organization_id' => ['sometimes', 'integer', 'min:1'],
            'province_id' => ['sometimes', 'integer', 'min:1'],
            'municipality_id' => ['sometimes', 'integer', 'min:1'],
            'entity_type_id' => ['sometimes', 'integer', 'min:1'],
            'address' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'fax' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:120'],
            'director_person_id' => ['nullable', 'integer', 'min:1'],
            'economic_director_person_id' => ['nullable', 'integer', 'min:1'],
            'parent_entity_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'social_purpose' => ['sometimes', 'string'],
        ];
    }
}
