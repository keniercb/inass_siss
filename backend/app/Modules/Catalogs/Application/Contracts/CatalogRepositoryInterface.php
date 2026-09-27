<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Application\Contracts;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\CatalogModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Persistence port for every catalog table of the module (ADR-11).
 *
 * One generic port covers the 18 catalog tables because they share
 * the standard shape (data model sections 5.1/5.2): the operations
 * are expressed in terms of the model class-string resolved from the
 * CatalogRegistry, so municipalities and agencies flow through the
 * same adapter as the uniform catalogs. Declared in the Application
 * layer so use cases depend on the abstraction (DIP) while Eloquent
 * stays confined to Infrastructure.
 *
 * @template TValue of CatalogModel
 */
interface CatalogRepositoryInterface
{
    /**
     * Paginated, searchable, ordered listing.
     *
     * @param  class-string<TValue>  $modelClass
     * @param  list<string>  $searchColumns  Columns matched with LIKE for the ?search= filter
     * @param  array<string, mixed>  $filters  Exact-match column filters
     * @param  list<string>  $with  Relations to eager load
     * @return LengthAwarePaginator<int, TValue>
     */
    public function paginate(
        string $modelClass,
        ?string $search,
        array $searchColumns,
        ?string $sortColumn,
        bool $descending,
        array $filters,
        array $with,
        int $page,
        int $perPage,
    ): LengthAwarePaginator;

    /**
     * @param  class-string<CatalogModel>  $modelClass
     */
    public function find(string $modelClass, int $id): ?CatalogModel;

    /**
     * Resolves deactivated entries too: detail endpoints keep
     * historical records reachable by id (RF-CAT-001) while listings
     * and reference validations stay active-only.
     *
     * @param  class-string<CatalogModel>  $modelClass
     */
    public function findIncludingDeactivated(string $modelClass, int $id): ?CatalogModel;

    /**
     * Whether at least one active row matches the conditions.
     *
     * @param  class-string<CatalogModel>  $modelClass
     * @param  array<string, mixed>  $conditions  Column => value map; null values become IS NULL
     */
    public function exists(string $modelClass, array $conditions, ?int $exceptId = null): bool;

    /**
     * Natural-key uniqueness probe (RN-008 mirrors in application
     * space to answer 422 semantics before hitting the constraint).
     * Includes deactivated rows: unique indexes cover them all.
     *
     * @param  class-string<CatalogModel>  $modelClass
     * @param  array<string, mixed>  $conditions  Column => value map; null values become IS NULL
     */
    public function existsAny(string $modelClass, array $conditions, ?int $exceptId = null): bool;

    /**
     * Whether any non-deactivated row of the dependent model still
     * references the given id (RF-CAT-001 deactivation guard).
     *
     * @param  class-string<CatalogModel>  $dependentModel
     */
    public function hasActiveReferences(string $dependentModel, string $foreignKey, int $id): bool;

    /**
     * Persists a new or changed catalog entry (stamps authorship via
     * the Shared AuditableObserver, ADR-14).
     */
    public function save(CatalogModel $model): void;

    /**
     * Logically deactivates the entry (soft delete, RF-CAT-001).
     */
    public function deactivate(CatalogModel $model): void;

    /**
     * Restores a logically deactivated entry (RF-AUD-004): fires the
     * restored model event so the activity trail records who brought
     * the row back.
     */
    public function restore(CatalogModel $model): void;
}
