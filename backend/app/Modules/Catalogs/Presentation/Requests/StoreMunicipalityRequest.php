<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload for creating a municipality (RF-CAT-002).
 *
 * province_id is nullable: the special municipality Isla de la
 * Juventud belongs to no province. Existence of the province and the
 * composite (province_id, code) uniqueness live in the
 * MunicipalityService.
 */
final class StoreMunicipalityRequest extends FormRequest
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
            'province_id' => ['nullable', 'integer'],
            'code' => ['required', 'string', 'alpha_num', 'max:4'],
            'name' => ['required', 'string', 'max:80'],
        ];
    }
}
