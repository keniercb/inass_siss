<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Presentation\Requests;

use App\Modules\Catalogs\Application\CatalogRegistry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload for updating a uniform catalog entry (RF-CAT-001).
 *
 * PATCH semantics: every field is optional. The code is validated
 * for format here only when sent; the service rejects any attempt to
 * change it (immutable integration identifier).
 */
final class UpdateCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string|array<string>>
     */
    public function rules(): array
    {
        $type = (string) $this->route('type');

        if (! CatalogRegistry::has($type)) {
            return [];
        }

        return CatalogRegistry::definition($type)->updateRules();
    }
}
