<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Infrastructure\Persistence;

use App\Modules\Organizations\Application\Contracts\EntityRepositoryInterface;
use App\Modules\Organizations\Infrastructure\Persistence\Models\Entity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Eloquent persistence for entities (ADR-11): the single data-access
 * point of the Organizations module for entities. Search implements
 * the RF-ENT-005 surface — fragments against code, NIT and social
 * purpose plus exact reference filters — ordered by code for stable
 * listings. Uniqueness probes include soft-deleted rows because the
 * code and the NIT stay reserved after deactivation, and the
 * hierarchy snapshot feeds both the tree and the RN-003 parent map.
 */
final class EloquentEntityRepository implements EntityRepositoryInterface
{
    /** Relations every read projection needs (single source). */
    private const WITH = ['organization', 'province', 'municipality', 'entityType', 'parent'];

    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = Entity::query()
            ->with(self::WITH)
            ->orderBy('code');

        if (isset($filters['q']) && $filters['q'] !== '') {
            $fragment = '%'.mb_strtolower((string) $filters['q']).'%';
            $query->where(function ($group) use ($fragment): void {
                $group->whereRaw('LOWER(code) LIKE ?', [$fragment])
                    ->orWhereRaw('LOWER(tax_id_number) LIKE ?', [$fragment])
                    ->orWhereRaw('LOWER(social_purpose) LIKE ?', [$fragment]);
            });
        }

        foreach (['organization_id', 'province_id', 'municipality_id', 'entity_type_id'] as $column) {
            if (isset($filters[$column]) && $filters[$column] !== null) {
                $query->where($column, (int) $filters[$column]);
            }
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return $paginator;
    }

    public function find(int $id): ?Entity
    {
        return Entity::query()
            ->with(self::WITH)
            ->find($id);
    }

    /** @return list<Entity> */
    public function hierarchyNodes(): array
    {
        return Entity::query()
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function existsByCode(string $code, ?int $exceptId = null): bool
    {
        return Entity::withTrashed()
            ->where('code', $code)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();
    }

    public function existsByTaxIdNumber(string $taxIdNumber, ?int $exceptId = null): bool
    {
        return Entity::withTrashed()
            ->where('tax_id_number', $taxIdNumber)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();
    }

    public function hasActiveChildren(int $entityId): bool
    {
        return Entity::query()
            ->where('parent_entity_id', $entityId)
            ->exists();
    }

    public function create(array $attributes): Entity
    {
        $entity = new Entity;
        $entity->fill($attributes);
        $entity->save();

        return $entity->refresh()->load(self::WITH);
    }

    public function update(Entity $entity, array $attributes): Entity
    {
        $entity->fill($attributes);
        $entity->save();

        return $entity->refresh()->load(self::WITH);
    }

    public function softDelete(Entity $entity): bool
    {
        return (bool) $entity->delete();
    }
}
