<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Requests;

use App\Modules\Security\Domain\Authorization\PermissionMatrix;
use App\Modules\Security\Presentation\Rules\ConformsToPasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * Account creation payload (S3.6, RF-SEG-001, ADR-24): name, email,
 * initial password (policy-checked) and at least one institutional
 * role. Semantic failures the request cannot see — an email reserved
 * by a deactivated account — answer 422 from the service.
 */
final class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string|ConformsToPasswordPolicy|In>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', new ConformsToPasswordPolicy],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', Rule::in(PermissionMatrix::roles())],
        ];
    }
}
