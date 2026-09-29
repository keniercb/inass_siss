<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Requests;

use App\Modules\Security\Domain\Authorization\PermissionMatrix;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Custom role creation payload (RF-SEG-002, ADR-26): a slug name
 * (the RoleName domain contract, re-checked by the service — defense
 * in depth), an optional description and at least one permission of
 * the matrix catalog. Institutional names are reserved: the service
 * answers 422 for them after this layer accepts the format.
 */
final class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string|\Illuminate\Validation\Rules\In>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:31', 'regex:/^[a-z][a-z0-9_]{1,30}$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => [
                'required',
                'string',
                'distinct',
                Rule::in(PermissionMatrix::permissions()),
            ],
        ];
    }
}
