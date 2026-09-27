<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application\Contracts;

use App\Modules\Organizations\Infrastructure\Persistence\Models\Entity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Use cases of the Organizations module for entities (RF-ENT-001,
 * RF-ENT-005).
 */
interface EntityServiceInterface
{
    /** Maximum depth served by the structure tree (RF-ENT-005). */
    public const TREE_MAX_DEPTH = 5;

    /**
     * Register an entity (RF-ENT-001) after validating the references,
     * the RN-004 geographic coherence and the natural-key uniqueness.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException semantic 422 per field
     */
    public function create(array $attributes): Entity;

    /**
     * Edit an entity: code and NIT are immutable, and the resulting
     * state re-validates references, coherence and the RN-003
     * acyclicity of the new parent.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException semantic 422 per field
     */
    public function update(int $id, array $attributes): ?Entity;

    /**
     * Search (RF-ENT-005: fragments against code/NIT/social purpose
     * plus reference filters).
     *
     * @param  array{q?: string, organization_id?: int, province_id?: int, municipality_id?: int, entity_type_id?: int}  $filters
     * @return LengthAwarePaginator<int, Entity>
     */
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator;

    public function get(int $id): ?Entity;

    /**
     * Hierarchy tree (RF-ENT-005): nested nodes up to
     * TREE_MAX_DEPTH levels; nodes cut at the maximum depth expose a
     * deeper flag instead of silently hiding their subtree.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(): array;

    /**
     * Soft delete (deactivation): refuses while active children hang
     * from the entity, so the hierarchy never orphans a live subtree.
     *
     * @throws ValidationException when active children exist
     */
    public function delete(int $id): bool;
}
