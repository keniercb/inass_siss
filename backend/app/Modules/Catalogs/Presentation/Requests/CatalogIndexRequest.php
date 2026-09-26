<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query parameters for every catalog listing (RF-CAT-006).
 *
 * Format-level validation only: the searchable columns and the sort
 * whitelist are resolved by the Application services from the
 * CatalogRegistry, and pagination caps are enforced here to protect
 * the database from oversized page requests.
 */
final class CatalogIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authentication is enforced by the auth:sanctum middleware;
        // catalog permissions arrive with RBAC in Sprint 3.
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string', 'in:name,code,id'],
            'order' => ['nullable', 'string', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
