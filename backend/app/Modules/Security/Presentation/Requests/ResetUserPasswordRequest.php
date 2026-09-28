<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Requests;

use App\Modules\Security\Presentation\Rules\ConformsToPasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Administrator password reset payload (S3.6, RF-SEC-001, ADR-24).
 */
final class ResetUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string|ConformsToPasswordPolicy>>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', new ConformsToPasswordPolicy],
        ];
    }
}
