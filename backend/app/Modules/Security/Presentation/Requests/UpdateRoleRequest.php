<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Requests;

use App\Modules\Security\Domain\Authorization\PermissionMatrix;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * Custom role edition payload (RF-SEG-002, ADR-26): PATCH semantics
 * — absent fields stay untouched, a permissions array REPLACES the
 * whole grant set (and must remain a non-empty subset of the matrix
 * catalog). The service keeps re-checking every rule (defense in
 * depth) and answers 422 for institutional roles before any write.
 */
final class UpdateRoleRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:31', 'regex:/^[a-z][a-z0-9_]{1,30}$/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array', 'min:1'],
            'permissions.*' => [
                'required',
                'string',
                'distinct',
                Rule::in(PermissionMatrix::permissions()),
            ],
        ];
    }
}
