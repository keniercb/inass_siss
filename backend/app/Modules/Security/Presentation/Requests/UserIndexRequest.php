<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Requests;

use App\Modules\Security\Domain\Authorization\PermissionMatrix;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query filters for the account directory (S3.6, ADR-24): free text
 * over name/email, exact institutional role and status. The
 * repository owns the semantics; this class only pins the wire
 * contract.
 */
final class UserIndexRequest extends FormRequest
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
        // String form of `in:` on purpose (PHPStan lesson, Task 18):
        // the dynamic role catalog is safer as a plain string rule.
        $roles = 'in:'.implode(',', PermissionMatrix::roles());

        return [
            'q' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', 'string', $roles],
            'status' => ['nullable', 'string', 'in:active,inactive,all'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Search filters resolved for the repository, without pagination
     * (the controller resolves page/per_page on its own).
     *
     * @return array{q?: string, role?: string, status?: string}
     */
    public function filters(): array
    {
        /** @var array{q?: string|null, role?: string|null, status?: string|null} $filters */
        $filters = $this->only(['q', 'role', 'status']);

        return array_filter($filters, static fn ($value): bool => $value !== null && $value !== '');
    }
}
