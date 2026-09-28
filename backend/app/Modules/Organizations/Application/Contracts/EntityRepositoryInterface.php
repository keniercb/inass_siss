<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application\Contracts;

use App\Modules\Organizations\Infrastructure\Persistence\Models\Entity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Persistence port of the Organizations module for entities (ADR-11).
 * Presentation and Application code type-hint this contract; only the
 * Infrastructure layer knows about Eloquent.
 */
interface EntityRepositoryInterface
{
    /**
     * Search with the RF-ENT-005 surface: fragments against code, NIT
     * and social purpose (entities carry no name column — the data
     * model identifies them by code/NIT, so the plan's "búsqueda por
     * nombre/NIT" maps to those three), plus exact filters on the
     * organizational and geographic references. Paginated.
     *
     * @param  array{q?: string, organization_id?: int, province_id?: int, municipality_id?: int, entity_type_id?: int}  $filters
     * @return LengthAwarePaginator<int, Entity>
     */
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator;

    /** Active entity by id; deactivated ones answer null (404). */
    public function find(int $id): ?Entity;

    /**
     * Active hierarchy snapshot ordered by id, used both to build the
     * RF-ENT-005 tree and to derive the parent map that feeds the
     * RN-003 acyclicity policy.
     *
     * @return list<Entity>
     */
    public function hierarchyNodes(): array;

    /**
     * Natural-key uniqueness probes including soft-deleted rows: code
     * and NIT stay reserved after deactivation (RN-001 mirror).
     */
    public function existsByCode(string $code, ?int $exceptId = null): bool;

    public function existsByTaxIdNumber(string $taxIdNumber, ?int $exceptId = null): bool;

    /**
     * Whether any active entity still declares the given entity as
     * its parent (deactivation guard: the hierarchy must not orphan
     * active subtrees).
     */
    public function hasActiveChildren(int $entityId): bool;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Entity;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Entity $entity, array $attributes): Entity;

    public function softDelete(Entity $entity): bool;
}
