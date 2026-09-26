<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Application\Contracts;

use App\Modules\Catalogs\Application\Exceptions\CatalogHasActiveReferencesException;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Municipality;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Use-case port for the municipalities catalog (RF-CAT-002).
 *
 * Dedicated contract because the natural key is composite
 * (province_id, code) and rows may carry a null province (special
 * municipality Isla de la Juventud); the generic catalog service
 * does not model either rule.
 */
interface MunicipalityServiceInterface
{
    /**
     * @return LengthAwarePaginator<int, Municipality>
     */
    public function list(
        ?int $provinceId,
        ?string $search,
        ?string $sort,
        string $order,
        int $page,
        int $perPage,
    ): LengthAwarePaginator;

    public function get(int $id): ?Municipality;

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException on composite-key duplication or unknown province
     */
    public function create(array $attributes): Municipality;

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException on composite-key duplication, unknown province or code immutability
     */
    public function update(int $id, array $attributes): ?Municipality;

    /**
     * @throws CatalogHasActiveReferencesException while active agencies reference the municipality
     */
    public function deactivate(int $id): bool;
}
