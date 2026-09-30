<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload for registering an entity (RF-ENT-001). Reference
 * existence, the RN-004 coherence and the RN-003 acyclicity live in
 * the EntityService, which answers with per-field 422 errors; these
 * rules pin shape and wire formats only.
 */
final class StoreEntityRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:15'],
            'name' => ['required', 'string', 'max:120'],
            'tax_id_number' => ['required', 'string', 'max:20'],
            'organization_id' => ['required', 'integer', 'min:1'],
            'province_id' => ['required', 'integer', 'min:1'],
            'municipality_id' => ['required', 'integer', 'min:1'],
            'entity_type_id' => ['required', 'integer', 'min:1'],
            'address' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'fax' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:120'],
            'director_person_id' => ['nullable', 'integer', 'min:1'],
            'economic_director_person_id' => ['nullable', 'integer', 'min:1'],
            'parent_entity_id' => ['nullable', 'integer', 'min:1'],
            'social_purpose' => ['required', 'string'],
        ];
    }
}
