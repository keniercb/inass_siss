<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Infrastructure\Persistence;

use App\Modules\Catalogs\Application\Contracts\CatalogRepositoryInterface;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\CatalogModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Eloquent implementation of the catalog repository port (ADR-11).
 *
 * The single place in the Catalogs module allowed to build queries
 * against the 18 catalog tables: every operation is expressed in
 * terms of the model class-string resolved from the CatalogRegistry,
 * so the adapter stays one class instead of eighteen near-identical
 * repositories. Sort columns arrive whitelisted from the Application
 * services, never raw from the request.
 *
 * @template TValue of CatalogModel
 *
 * @implements CatalogRepositoryInterface<TValue>
 */
final class EloquentCatalogRepository implements CatalogRepositoryInterface
{
    /**
     * @param  class-string<TValue>  $modelClass
     * @param  list<string>  $searchColumns
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $with
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
    ): LengthAwarePaginator {
        $query = $modelClass::query()->with($with);

        foreach ($filters as $column => $value) {
            if ($value === null) {
                $query->whereNull($column);
            } else {
                $query->where($column, $value);
            }
        }

        if ($search !== null && $search !== '' && $searchColumns !== []) {
            $query->where(function ($builder) use ($search, $searchColumns): void {
                foreach ($searchColumns as $column) {
                    $builder->orWhere($column, 'like', "%{$search}%");
                }
            });
        }

        $query->orderBy($sortColumn ?? 'name', $descending ? 'desc' : 'asc');

        /** @var LengthAwarePaginator */
        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    public function find(string $modelClass, int $id): ?CatalogModel
    {
        $model = $modelClass::query()->find($id);

        return $model instanceof CatalogModel ? $model : null;
    }

    /**
     * Resolves entries that were logically deactivated too: the
     * detail endpoint keeps historical records reachable by id
     * (RF-CAT-001) even though listings exclude them.
     *
     * @param  class-string<CatalogModel>  $modelClass
     */
    public function findIncludingDeactivated(string $modelClass, int $id): ?CatalogModel
    {
        $model = $modelClass::query()->withTrashed()->find($id);

        return $model instanceof CatalogModel ? $model : null;
    }

    public function exists(string $modelClass, array $conditions, ?int $exceptId = null): bool
    {
        return $this->probe($modelClass, $conditions, $exceptId, false);
    }

    /**
     * Uniqueness probe that matches the database constraint
     * semantics (RN-008): unique indexes cover deactivated rows as
     * well, so natural-key checks must include them.
     *
     * @param  class-string<CatalogModel>  $modelClass
     * @param  array<string, mixed>  $conditions
     */
    public function existsAny(string $modelClass, array $conditions, ?int $exceptId = null): bool
    {
        return $this->probe($modelClass, $conditions, $exceptId, true);
    }

    /**
     * @param  class-string<CatalogModel>  $modelClass
     * @param  array<string, mixed>  $conditions
     */
    private function probe(string $modelClass, array $conditions, ?int $exceptId, bool $withDeactivated): bool
    {
        $query = $withDeactivated
            ? $modelClass::query()->withTrashed()
            : $modelClass::query();

        foreach ($conditions as $column => $value) {
            if ($value === null) {
                $query->whereNull($column);
            } else {
                $query->where($column, $value);
            }
        }

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        return $query->exists();
    }

    public function hasActiveReferences(string $dependentModel, string $foreignKey, int $id): bool
    {
        // Soft deletes' global scope keeps deactivated dependents out,
        // matching RF-CAT-001 ("referencias activas").
        return $dependentModel::query()->where($foreignKey, $id)->exists();
    }

    public function save(CatalogModel $model): void
    {
        $model->save();
    }

    public function deactivate(CatalogModel $model): void
    {
        $model->delete();
    }

    public function restore(CatalogModel $model): void
    {
        $model->restore();
    }
}
