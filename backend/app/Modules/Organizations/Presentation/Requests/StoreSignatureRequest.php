<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload for registering an authorized signature (RF-ENT-003). The
 * tern uniqueness and the window ordering (RN-006) live in the
 * SignatureService; these rules pin shape and wire formats.
 */
final class StoreSignatureRequest extends FormRequest
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
            'entity_id' => ['required', 'integer', 'min:1'],
            'person_id' => ['required', 'integer', 'min:1'],
            'position_id' => ['required', 'integer', 'min:1'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
