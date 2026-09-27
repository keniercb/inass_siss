<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Application\Contracts;

use App\Modules\Catalogs\Application\Exceptions\CatalogEntryNotDeletedException;
use App\Modules\Catalogs\Application\Exceptions\CatalogHasActiveReferencesException;
use App\Modules\Catalogs\Application\Exceptions\UnknownCatalogException;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\CatalogModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Generic use-case port for the uniform catalogs (RF-CAT-001, ADR-15).
 *
 * One contract drives the /api/v1/catalogs/{type} resource for every
 * catalog listed in the CatalogRegistry. Controllers depend on this
 * abstraction (DIP, ADR-12) so the generic behavior stays unit-
 * testable and can be decorated without touching HTTP code.
 */
interface CatalogServiceInterface
{
    /**
     * @return LengthAwarePaginator<int, CatalogModel>
     *
     * @throws UnknownCatalogException
     */
    public function list(
        string $type,
        ?string $search,
        ?string $sort,
        string $order,
        int $page,
        int $perPage,
    ): LengthAwarePaginator;

    /**
     * @throws UnknownCatalogException
     */
    public function get(string $type, int $id): ?CatalogModel;

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws UnknownCatalogException
     * @throws ValidationException when a natural key is already in use (RN-008)
     */
    public function create(string $type, array $attributes): CatalogModel;

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws UnknownCatalogException
     * @throws ValidationException on unique violations or code immutability (RF-CAT-001)
     */
    public function update(string $type, int $id, array $attributes): ?CatalogModel;

    /**
     * Logical deactivation (RF-CAT-001), blocked while active
     * references exist.
     *
     * @throws UnknownCatalogException
     * @throws CatalogHasActiveReferencesException
     */
    public function deactivate(string $type, int $id): bool;

    /**
     * Restores a logically deactivated entry (RF-AUD-004): audited by
     * the Shared AuditTrailObserver through the restored event.
     *
     * @throws UnknownCatalogException
     * @throws CatalogEntryNotDeletedException when the entry is already active
     */
    public function restore(string $type, int $id): bool;
}
