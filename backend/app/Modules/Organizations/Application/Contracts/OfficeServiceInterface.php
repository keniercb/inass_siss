<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application\Contracts;

use App\Modules\Organizations\Infrastructure\Persistence\Models\Office;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Use cases of the Organizations module for offices (RF-ENT-002,
 * RF-ENT-005).
 */
interface OfficeServiceInterface
{
    /**
     * Register an office (RF-ENT-002) after validating the references,
     * the RN-004 geographic coherence and the territorial structure
     * (ADR-31): per-scope uniqueness, existence of the required
     * superior and the parent derived from the type.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException semantic 422 per field
     */
    public function create(array $attributes): Office;

    /**
     * Edit an office: the resulting state re-validates references,
     * coherence, the RN-003 acyclicity of the generic path and the
     * territorial structure of ADR-31 (uniqueness excluding itself
     * and the parent re-derived from the resulting type), while
     * offices with active children keep their type and territory.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException semantic 422 per field
     */
    public function update(int $id, array $attributes): ?Office;

    /**
     * @param  array{q?: string, office_type_id?: int, province_id?: int, municipality_id?: int}  $filters
     * @return LengthAwarePaginator<int, Office>
     */
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator;

    public function get(int $id): ?Office;

    /**
     * Hierarchy tree (RF-ENT-005), same depth contract as entities.
     * Each node carries the case counts of the office itself and of
     * its ámbito (itself + active descendants, ADR-28).
     *
     * @return list<array<string, mixed>>
     */
    public function tree(): array;

    /**
     * Case counts of one office (RF-ENT-005 second part, ADR-28):
     * expedientes tramitados by the office and by its ámbito
     * (itself + active descendant offices).
     *
     * @return array{cases_count: int, scope_cases_count: int}
     */
    public function caseCountSummary(Office $office): array;

    /**
     * Soft delete (deactivation): refuses while active children hang
     * from the office.
     *
     * @throws ValidationException when active children exist
     */
    public function delete(int $id): bool;
}
