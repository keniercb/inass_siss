<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Requests;

use App\Modules\Security\Presentation\Rules\ConformsToPasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Self-service password renewal payload (RF-SEG-001 "renovación",
 * ADR-24): the current secret proves possession, the new one must
 * satisfy the policy and differ from the current (service rule).
 */
final class ChangeOwnPasswordRequest extends FormRequest
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
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', new ConformsToPasswordPolicy],
        ];
    }
}
