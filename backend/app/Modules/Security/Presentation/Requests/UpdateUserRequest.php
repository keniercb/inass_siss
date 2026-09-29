<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Account edition payload (S3.6, ADR-24): display name and/or the
 * full role assignment — institutional OR custom roles, resolved
 * against the roles directory (RF-SEG-002, ADR-26). The email may
 * travel for read-modify-write clients but is immutable — a change
 * answers 422 from the service with the immutability message
 * (natural-key doctrine). office_id follows PATCH semantics
 * (ADR-29): present in the payload — even as an explicit null — it
 * reassigns or clears the territorial office; absent, it stays
 * untouched. Must reference an ACTIVE office.
 */
final class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string|Rule|Exists>>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'roles' => ['nullable', 'array', 'min:1'],
            'roles.*' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'office_id' => [
                'nullable',
                'integer',
                Rule::exists('offices', 'id')->whereNull('deleted_at'),
            ],
        ];
    }
}
