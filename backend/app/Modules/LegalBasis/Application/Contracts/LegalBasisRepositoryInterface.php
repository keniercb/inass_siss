<?php

declare(strict_types=1);

namespace App\Modules\LegalBasis\Application\Contracts;

use App\Modules\LegalBasis\Domain\LegalBasisStatus;
use App\Modules\LegalBasis\Infrastructure\Persistence\Models\LegalBasis;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Persistence port of the LegalBasis module (ADR-11). The
 * derived-status filter resolves the validity windows against the
 * Shared Clock, so "effective" means in force today.
 */
interface LegalBasisRepositoryInterface
{
    /**
     * Search with the RF-LEG-004 surface: fragments against number
     * and reference, plus exact filters on type, issuing
     * organization, year and the derived status. Paginated.
     *
     * @param  array{q?: string, legal_basis_type_id?: int, organization_id?: int, year?: int, status?: LegalBasisStatus}  $filters
     * @return LengthAwarePaginator<int, LegalBasis>
     */
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator;

    /** Active legal basis by id; deactivated ones answer null (404). */
    public function find(int $id): ?LegalBasis;

    /**
     * Tern uniqueness probe including soft-deleted rows: the
     * type+number+year combination stays reserved by history.
     */
    public function ternExists(int $typeId, string $number, int $year, ?int $exceptId = null): bool;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): LegalBasis;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(LegalBasis $basis, array $attributes): LegalBasis;

    public function softDelete(LegalBasis $basis): bool;
}
