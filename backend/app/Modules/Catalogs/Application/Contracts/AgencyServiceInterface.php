<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Application\Contracts;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\Agency;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Use-case port for bank agencies (RF-CAT-003).
 *
 * Dedicated contract because an agency validates three foreign
 * references plus the geographic coherence rule RN-04 (the
 * municipality must belong to the declared province), which is also
 * guaranteed at the database level by a composite foreign key.
 */
interface AgencyServiceInterface
{
    /**
     * @return LengthAwarePaginator<int, Agency>
     */
    public function list(
        ?int $provinceId,
        ?int $municipalityId,
        ?int $agencyTypeId,
        ?string $search,
        ?string $sort,
        string $order,
        int $page,
        int $perPage,
    ): LengthAwarePaginator;

    public function get(int $id): ?Agency;

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException on unique violations, unknown references or RN-04 incoherence
     */
    public function create(array $attributes): Agency;

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException on unique violations, unknown references, RN-04 incoherence or code immutability
     */
    public function update(int $id, array $attributes): ?Agency;

    public function deactivate(int $id): bool;
}
