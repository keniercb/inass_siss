<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Presentation\Requests;

use App\Modules\Catalogs\Application\CatalogRegistry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload for creating a uniform catalog entry (RF-CAT-001).
 *
 * The rules come from the CatalogDefinition of the {type} route
 * parameter, so each catalog validates its own shape from the same
 * source of truth the service uses. Natural-key uniqueness and code
 * immutability are business invariants and live in the CatalogService
 * (422 with per-field errors), never in the request layer.
 */
final class StoreCatalogRequest extends FormRequest
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
            // Unknown catalogs are a 404 handled by the controller,
            // not a validation problem of the payload.
            return [];
        }

        return CatalogRegistry::definition($type)->storeRules();
    }
}
