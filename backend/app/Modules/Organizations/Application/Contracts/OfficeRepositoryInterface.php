<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application\Contracts;

use App\Modules\Organizations\Infrastructure\Persistence\Models\Office;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Persistence port of the Organizations module for offices (ADR-11).
 */
interface OfficeRepositoryInterface
{
    /**
     * Search with exact filters on type and geography plus fragments
     * against the address (offices carry no name column, data model
     * 5.5). Paginated.
     *
     * @param  array{q?: string, office_type_id?: int, province_id?: int, municipality_id?: int}  $filters
     * @return LengthAwarePaginator<int, Office>
     */
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator;

    /** Active office by id; deactivated ones answer null (404). */
    public function find(int $id): ?Office;

    /**
     * Active hierarchy snapshot with the type eager loaded, ordered
     * by id: input of the RF-ENT-005 tree and of the RN-003 parent
     * map.
     *
     * @return list<Office>
     */
    public function hierarchyNodes(): array;

    /**
     * Whether any active office still declares the given office as
     * its parent (deactivation guard).
     */
    public function hasActiveChildren(int $officeId): bool;

    /**
     * The active office of the given type code — optionally narrowed
     * to a province and a municipality — excluding an office id: the
     * lookup behind the territorial structure (ADR-31), which
     * detects both the per-scope uniqueness conflicts (a second
     * national, provincial or municipal office) and the required
     * superior of each type. Resolves the type by code, so the
     * office type catalog stays the single source of truth.
     */
    public function findActiveOfType(string $typeCode, ?int $provinceId = null, ?int $municipalityId = null, ?int $exceptId = null): ?Office;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Office;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Office $office, array $attributes): Office;

    public function softDelete(Office $office): bool;
}
