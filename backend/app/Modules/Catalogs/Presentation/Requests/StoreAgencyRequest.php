<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload for creating an agency (RF-CAT-003).
 *
 * Reference existence and the RN-04 coherence rule (the municipality
 * must belong to the declared province) live in the AgencyService,
 * which answers with per-field 422 errors.
 */
final class StoreAgencyRequest extends FormRequest
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
            'code' => ['required', 'string', 'alpha_num', 'max:10'],
            'name' => ['required', 'string', 'max:120'],
            'province_id' => ['required', 'integer'],
            'municipality_id' => ['required', 'integer'],
            'agency_type_id' => ['required', 'integer'],
        ];
    }
}
