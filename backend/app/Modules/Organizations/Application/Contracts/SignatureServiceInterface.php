<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application\Contracts;

use App\Modules\Organizations\Domain\SignatureStatus;
use App\Modules\Organizations\Infrastructure\Persistence\Models\AuthorizedSignature;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Use cases of the Organizations module for authorized signatures
 * (RF-ENT-003).
 */
interface SignatureServiceInterface
{
    /**
     * Register a signature after the tern uniqueness: the
     * entity+person+position combination is unique including revoked
     * history (the row reservation answers a semantic 422).
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException semantic 422 per field
     */
    public function create(array $attributes): AuthorizedSignature;

    /**
     * Edit the validity window; the resulting window re-validates the
     * RN-006 ordering (valid_to never precedes valid_from).
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException semantic 422 per field
     */
    public function update(int $id, array $attributes): ?AuthorizedSignature;

    /**
     * Search by references plus derived status.
     *
     * @param  array{entity_id?: int, person_id?: int, position_id?: int, status?: SignatureStatus}  $filters
     * @return LengthAwarePaginator<int, AuthorizedSignature>
     */
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator;

    public function get(int $id): ?AuthorizedSignature;

    /**
     * Logical revocation (soft delete): the row stays as the
     * historical record of the signature and keeps the tern reserved
     * (RF-ENT-003).
     */
    public function delete(int $id): bool;
}
