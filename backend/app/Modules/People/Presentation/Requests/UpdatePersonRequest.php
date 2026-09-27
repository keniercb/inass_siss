<?php

declare(strict_types=1);

namespace App\Modules\People\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload for editing a person (RF-PER-002).
 *
 * Every field is optional (partial update), but two fields are
 * forbidden on purpose: the identity number is immutable after
 * creation (RN-001) and the death date belongs to the audited
 * lifecycle endpoint (RF-PER-003) — `prohibited` answers a field
 * 422 so the operator learns the right surface instead of silently
 * ignoring the payload.
 */
final class UpdatePersonRequest extends FormRequest
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
            'identity_number' => ['prohibited'],
            'death_date' => ['prohibited'],
            'first_name' => ['sometimes', 'string', 'max:50'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:50'],
            'first_surname' => ['sometimes', 'string', 'max:50'],
            'second_surname' => ['sometimes', 'nullable', 'string', 'max:50'],
            'sex' => ['sometimes', 'string', 'max:1', 'in:M,F'],
            'race_id' => ['sometimes', 'nullable', 'integer', 'exists:races,id'],
            'address' => ['sometimes', 'string', 'max:255'],
            'birth_date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],
            'father_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mother_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'citizen_card_id' => ['sometimes', 'nullable', 'string', 'max:30'],
        ];
    }
}
