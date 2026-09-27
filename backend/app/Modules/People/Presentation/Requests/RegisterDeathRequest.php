<?php

declare(strict_types=1);

namespace App\Modules\People\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload for the death registration endpoint (RF-PER-003). The
 * date ordering guards (strictly after birth, never in the future)
 * are semantic: they need the stored person and the Clock, so the
 * service enforces them behind a field-level 422, backed by the
 * chk_people_dates CHECK.
 */
final class RegisterDeathRequest extends FormRequest
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
            'death_date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
