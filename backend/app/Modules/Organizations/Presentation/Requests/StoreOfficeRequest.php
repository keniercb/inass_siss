<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload for registering an office (RF-ENT-002). Reference
 * existence, the RN-004 coherence and the RN-003 acyclicity live in
 * the OfficeService, which answers with per-field 422 errors.
 */
final class StoreOfficeRequest extends FormRequest
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
            'office_type_id' => ['required', 'integer', 'min:1'],
            'province_id' => ['required', 'integer', 'min:1'],
            'municipality_id' => ['required', 'integer', 'min:1'],
            'address' => ['required', 'string', 'max:255'],
            'parent_office_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
