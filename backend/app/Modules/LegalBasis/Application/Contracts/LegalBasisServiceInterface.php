<?php

declare(strict_types=1);

namespace App\Modules\LegalBasis\Application\Contracts;

use App\Modules\LegalBasis\Domain\LegalBasisStatus;
use App\Modules\LegalBasis\Infrastructure\Persistence\Models\LegalBasis;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Use cases of the LegalBasis module (RF-LEG-002..004).
 */
interface LegalBasisServiceInterface
{
    /**
     * Register a legal basis (RF-LEG-002) after validating the
     * references, the RN-006 date ordering and the tern uniqueness.
     * The year is derived from issue_date (H-11) and never accepted
     * from the payload.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException semantic 422 per field
     */
    public function create(array $attributes): LegalBasis;

    /**
     * Edit the registration data (reference, dates, issuing
     * organization): the tern identity (type, number, issue_date)
     * is immutable and the resulting dates revalidate RN-006.
     * Derogation is set or corrected here — an auditable date edit,
     * never a destructive action.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException semantic 422 per field
     */
    public function update(int $id, array $attributes): ?LegalBasis;

    /**
     * Search (RF-LEG-004: year, type, issuing organization and
     * reference text) plus the derived status filter — the selector
     * of vigentes that the expediente approval consumes (RF-LEG-003).
     *
     * @param  array{q?: string, legal_basis_type_id?: int, organization_id?: int, year?: int, status?: LegalBasisStatus}  $filters
     * @return LengthAwarePaginator<int, LegalBasis>
     */
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator;

    public function get(int $id): ?LegalBasis;

    /**
     * Soft delete (deactivation): the tern stays reserved and the
     * deletion lands in the audit trail. Future expediente
     * references (F3) will be guarded by the FK RESTRICT.
     */
    public function delete(int $id): bool;
}
