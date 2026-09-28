<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload for editing the validity window of a signature
 * (RF-ENT-003). The tern is not editable: it identifies the
 * historical record. Only the window fields travel here; the
 * resulting window is revalidated against RN-006 ordering by the
 * service (merged with the stored dates).
 */
final class UpdateSignatureRequest extends FormRequest
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
            'valid_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'valid_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
