<?php

declare(strict_types=1);

namespace App\Modules\People\Presentation\Requests;

use App\Modules\People\Presentation\Rules\CubanIdentity;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload for registering a person (RF-PER-001).
 *
 * The identity number is validated structurally by the Shared value
 * object through the CubanIdentity rule (RN-001); its uniqueness is
 * probed by the service to answer the RF-PER-005 semantics (409 with
 * the registered person) and backed by the database UNIQUE index.
 * Mandatory fields follow the data model (section 5.4); death is
 * never set here — it has its own audited lifecycle endpoint
 * (RF-PER-003). `confirm` carries the operator's duplicate warning
 * acknowledgment (RF-PER-005).
 */
final class StorePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string|CubanIdentity>>
     */
    public function rules(): array
    {
        return [
            'identity_number' => ['required', 'string', 'digits:11', new CubanIdentity],
            'first_name' => ['required', 'string', 'max:50'],
            'middle_name' => ['nullable', 'string', 'max:50'],
            'first_surname' => ['required', 'string', 'max:50'],
            'second_surname' => ['nullable', 'string', 'max:50'],
            'sex' => ['required', 'string', 'max:1', 'in:M,F'],
            'race_id' => ['nullable', 'integer', 'exists:races,id'],
            'address' => ['required', 'string', 'max:255'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'father_name' => ['nullable', 'string', 'max:120'],
            'mother_name' => ['nullable', 'string', 'max:120'],
            'citizen_card_id' => ['nullable', 'string', 'max:30'],
            'confirm' => ['nullable', 'boolean'],
        ];
    }
}
