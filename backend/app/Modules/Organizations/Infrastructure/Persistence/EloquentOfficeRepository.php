<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Infrastructure\Persistence;

use App\Modules\Organizations\Application\Contracts\OfficeRepositoryInterface;
use App\Modules\Organizations\Infrastructure\Persistence\Models\Office;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * Eloquent persistence for offices (ADR-11): search across the
 * address and exact filters on type and geography, hierarchy
 * snapshot with the type eager loaded (input of the tree and of the
 * RN-003 parent map) and the active-children deactivation guard.
 */
final class EloquentOfficeRepository implements OfficeRepositoryInterface
{
    /** Relations every read projection needs (single source). */
    private const WITH = ['officeType', 'province', 'municipality', 'parent.officeType'];

    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = Office::query()
            ->with(self::WITH)
            ->orderBy('id');

        if (isset($filters['q']) && $filters['q'] !== '') {
            $fragment = '%'.mb_strtolower((string) $filters['q']).'%';
            $query->whereRaw('LOWER(address) LIKE ?', [$fragment]);
        }

        foreach (['office_type_id', 'province_id', 'municipality_id'] as $column) {
            if (isset($filters[$column]) && $filters[$column] !== null) {
                $query->where($column, (int) $filters[$column]);
            }
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return $paginator;
    }

    public function find(int $id): ?Office
    {
        return Office::query()
            ->with(self::WITH)
            ->find($id);
    }

    /** @return list<Office> */
    public function hierarchyNodes(): array
    {
        return Office::query()
            ->with('officeType')
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function hasActiveChildren(int $officeId): bool
    {
        return Office::query()
            ->where('parent_office_id', $officeId)
            ->exists();
    }

    public function findActiveOfType(string $typeCode, ?int $provinceId = null, ?int $municipalityId = null, ?int $exceptId = null): ?Office
    {
        $query = Office::query()
            ->whereHas('officeType', fn (EloquentBuilder $type): EloquentBuilder => $type->where('code', $typeCode))
            ->when($provinceId !== null, fn (EloquentBuilder $query): EloquentBuilder => $query->where('province_id', $provinceId))
            ->when($municipalityId !== null, fn (EloquentBuilder $query): EloquentBuilder => $query->where('municipality_id', $municipalityId))
            ->when($exceptId !== null, fn (EloquentBuilder $query): EloquentBuilder => $query->whereKeyNot($exceptId))
            ->orderBy('id');

        /** @var Office|null $office */
        $office = $query->first();

        return $office;
    }

    public function create(array $attributes): Office
    {
        $office = new Office;
        $office->fill($attributes);
        $office->save();

        return $office->refresh()->load(self::WITH);
    }

    public function update(Office $office, array $attributes): Office
    {
        $office->fill($attributes);
        $office->save();

        return $office->refresh()->load(self::WITH);
    }

    public function softDelete(Office $office): bool
    {
        return (bool) $office->delete();
    }
}
