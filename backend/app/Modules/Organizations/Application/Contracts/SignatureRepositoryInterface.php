<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application\Contracts;

use App\Modules\Organizations\Domain\SignatureStatus;
use App\Modules\Organizations\Infrastructure\Persistence\Models\AuthorizedSignature;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Persistence port of the Organizations module for authorized
 * signatures (ADR-11). The status filter resolves the derived state
 * against the Shared Clock, so "active" means in force today.
 */
interface SignatureRepositoryInterface
{
    /**
     * Search by the signature references plus the derived status.
     *
     * @param  array{entity_id?: int, person_id?: int, position_id?: int, status?: SignatureStatus}  $filters
     * @return LengthAwarePaginator<int, AuthorizedSignature>
     */
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator;

    /** Active signature by id; revoked (soft-deleted) ones answer null. */
    public function find(int $id): ?AuthorizedSignature;

    /**
     * Tern uniqueness probe including revoked rows: the
     * entity+person+position combination stays reserved by history
     * (RF-ENT-003, the UNIQUE index is the last defense).
     */
    public function ternExists(int $entityId, int $personId, int $positionId, ?int $exceptId = null): bool;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): AuthorizedSignature;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(AuthorizedSignature $signature, array $attributes): AuthorizedSignature;

    /**
     * Logical revocation: the row stays as history (RF-ENT-003).
     */
    public function softDelete(AuthorizedSignature $signature): bool;
}
