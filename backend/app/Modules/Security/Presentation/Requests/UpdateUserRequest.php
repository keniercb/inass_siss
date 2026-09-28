<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Requests;

use App\Modules\Security\Domain\Authorization\PermissionMatrix;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * Account edition payload (S3.6, ADR-24): display name and/or the
 * full role assignment. The email may travel for read-modify-write
 * clients but is immutable — a change answers 422 from the service
 * with the immutability message (natural-key doctrine).
 */
final class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string|In>>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'roles' => ['nullable', 'array', 'min:1'],
            'roles.*' => ['required', 'string', Rule::in(PermissionMatrix::roles())],
        ];
    }
}
