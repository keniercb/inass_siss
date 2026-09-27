<?php

declare(strict_types=1);

namespace App\Modules\LegalBasis\Infrastructure\Persistence;

use App\Modules\LegalBasis\Application\Contracts\LegalBasisRepositoryInterface;
use App\Modules\LegalBasis\Domain\LegalBasisStatus;
use App\Modules\LegalBasis\Infrastructure\Persistence\Models\LegalBasis;
use App\Modules\Shared\Contracts\ClockInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Eloquent persistence for legal bases (ADR-11): search implements
 * the RF-LEG-004 surface — fragments against number and reference
 * plus exact filters on type, issuing organization and year — and
 * the derived-status filter resolves the validity windows against
 * the Shared Clock (Infrastructure resolves time only through the
 * port, same discipline as the signature repository). The tern
 * probe includes deactivated rows because the UNIQUE index covers
 * the whole history.
 */
final class EloquentLegalBasisRepository implements LegalBasisRepositoryInterface
{
    /** Relations every read projection needs (single source). */
    private const WITH = ['type', 'organization'];

    public function __construct(
        private readonly ClockInterface $clock,
    ) {}

    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = LegalBasis::query()
            ->with(self::WITH)
            ->orderBy('id');

        if (isset($filters['q']) && $filters['q'] !== '') {
            $fragment = '%'.mb_strtolower((string) $filters['q']).'%';
            $query->where(function ($group) use ($fragment): void {
                $group->whereRaw('LOWER(number) LIKE ?', [$fragment])
                    ->orWhereRaw('LOWER(reference) LIKE ?', [$fragment]);
            });
        }

        // organization_id is the wire name of issuing_organization_id.
        $columns = [
            'legal_basis_type_id' => 'legal_basis_type_id',
            'organization_id' => 'issuing_organization_id',
            'year' => 'year',
        ];
        foreach ($columns as $filter => $column) {
            if (isset($filters[$filter]) && $filters[$filter] !== null) {
                $query->where($column, (int) $filters[$filter]);
            }
        }

        if (isset($filters['status']) && $filters['status'] instanceof LegalBasisStatus) {
            $today = $this->clock->now()->format('Y-m-d');

            match ($filters['status']) {
                // RF-LEG-003: in force — already effective and not
                // derogated (inclusive cut on the derogation day).
                LegalBasisStatus::Effective => $query->where(function ($group) use ($today): void {
                    $group->where('effective_date', '<=', $today)
                        ->where(function ($window) use ($today): void {
                            $window->whereNull('derogation_date')->orWhere('derogation_date', '>', $today);
                        });
                }),
                LegalBasisStatus::Future => $query->where('effective_date', '>', $today),
                LegalBasisStatus::Derogated => $query->where('derogation_date', '<=', $today),
            };
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return $paginator;
    }

    public function find(int $id): ?LegalBasis
    {
        return LegalBasis::query()
            ->with(self::WITH)
            ->find($id);
    }

    public function ternExists(int $typeId, string $number, int $year, ?int $exceptId = null): bool
    {
        // withTrashed on purpose: the tern of a deactivated norm
        // stays reserved; history cannot be overwritten.
        return LegalBasis::withTrashed()
            ->where('legal_basis_type_id', $typeId)
            ->where('number', $number)
            ->where('year', $year)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();
    }

    public function create(array $attributes): LegalBasis
    {
        $basis = new LegalBasis;
        $basis->fill($attributes);
        $basis->save();

        return $basis->refresh()->load(self::WITH);
    }

    public function update(LegalBasis $basis, array $attributes): LegalBasis
    {
        $basis->fill($attributes);
        $basis->save();

        return $basis->refresh()->load(self::WITH);
    }

    public function softDelete(LegalBasis $basis): bool
    {
        return (bool) $basis->delete();
    }
}
