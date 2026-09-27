<?php

declare(strict_types=1);

namespace App\Modules\LegalBasis\Application\Services;

use App\Modules\Catalogs\Application\Contracts\CatalogRepositoryInterface;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\CatalogModel;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\LegalBasisType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Organization;
use App\Modules\LegalBasis\Application\Contracts\LegalBasisRepositoryInterface;
use App\Modules\LegalBasis\Application\Contracts\LegalBasisServiceInterface;
use App\Modules\LegalBasis\Domain\LegalBasisStatus;
use App\Modules\LegalBasis\Infrastructure\Persistence\Models\LegalBasis;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Use cases for the legal corpus (RF-LEG-002..004, S4.4-S4.5).
 *
 * The type+number+year tern is the natural identity: unique
 * including deactivated rows (the reservation answers a semantic 422
 * before touching the UNIQUE index) and immutable after creation,
 * with the year derived from issue_date (H-11) inside this service —
 * the payload never carries it. The date ordering (RN-006:
 * effective_date >= issue_date and derogation_date >=
 * effective_date) is validated against the RESULTING state on both
 * create and update, with the database CHECKs as the last line.
 * Whether a norm is in force is derived at read time (Domain
 * LegalBasisStatus); the expediente approval selector (RF-LEG-003)
 * consumes the status=effective filter, and the "forcing a derogated
 * basis with a warning" rule lands with PensionCases (F3), which
 * owns the approval transition.
 */
final class LegalBasisService implements LegalBasisServiceInterface
{
    private const PAYLOAD_COLUMNS = [
        'legal_basis_type_id', 'number', 'issue_date', 'effective_date',
        'derogation_date', 'issuing_organization_id', 'reference',
    ];

    /**
     * @param  CatalogRepositoryInterface<CatalogModel>  $catalogs
     */
    public function __construct(
        private readonly LegalBasisRepositoryInterface $bases,
        private readonly CatalogRepositoryInterface $catalogs,
    ) {}

    public function create(array $attributes): LegalBasis
    {
        $payload = $this->acceptedPayload($attributes);

        $this->assertMandatoryKeys($payload, ['legal_basis_type_id', 'number', 'issue_date', 'effective_date', 'issuing_organization_id']);
        $this->assertReferencesAreValid($payload);
        $resulting = $this->resultingDates($payload, null);
        $this->assertDateOrdering($resulting['issue_date'] ?? null, $resulting['effective_date'] ?? null, $resulting['derogation_date'] ?? null);

        // H-11: the year is derived, never trusted from the wire.
        $payload['year'] = (int) (new \DateTimeImmutable((string) $resulting['issue_date']))->format('Y');

        $this->assertTernIsFree((int) $payload['legal_basis_type_id'], (string) $payload['number'], $payload['year'], null);

        return $this->bases->create($payload);
    }

    public function update(int $id, array $attributes): ?LegalBasis
    {
        $basis = $this->bases->find($id);

        if ($basis === null) {
            return null;
        }

        $payload = $this->acceptedPayload($attributes);

        if ($payload === []) {
            return $basis;
        }

        $this->assertIdentityIsImmutable($basis, $payload);

        // RN-006 against the resulting state: patched dates merge
        // with the stored ones before the ordering check.
        $resulting = $this->resultingDates($payload, $basis);
        $this->assertDateOrdering($resulting['issue_date'] ?? null, $resulting['effective_date'] ?? null, $resulting['derogation_date'] ?? null);

        if (isset($payload['issuing_organization_id'])) {
            $this->assertReferencesAreValid($payload);
        }

        return $this->bases->update($basis, $payload);
    }

    /**
     * @param  array{q?: string, legal_basis_type_id?: int, organization_id?: int, year?: int, status?: LegalBasisStatus}  $filters
     * @return LengthAwarePaginator<int, LegalBasis>
     */
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->bases->search($filters, $page, $perPage);
    }

    public function get(int $id): ?LegalBasis
    {
        return $this->bases->find($id);
    }

    public function delete(int $id): bool
    {
        $basis = $this->bases->find($id);

        if ($basis === null) {
            return false;
        }

        return $this->bases->softDelete($basis);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function acceptedPayload(array $attributes): array
    {
        $accepted = array_flip(self::PAYLOAD_COLUMNS);

        return array_intersect_key($attributes, $accepted);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function assertMandatoryKeys(array $payload, array $keys): void
    {
        $missing = [];
        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload) || $payload[$key] === null || $payload[$key] === '') {
                $missing[$key] = 'The field is required.';
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }

    /**
     * Existence of the type and the issuing organization, each
     * answering with its own field error.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertReferencesAreValid(array $payload): void
    {
        $typeId = $payload['legal_basis_type_id'] ?? null;

        if ($typeId === null || ! $this->catalogs->exists(LegalBasisType::class, ['id' => $typeId])) {
            throw ValidationException::withMessages([
                'legal_basis_type_id' => 'The selected legal basis type does not exist.',
            ]);
        }

        $organizationId = $payload['issuing_organization_id'] ?? null;

        if ($organizationId === null || ! $this->catalogs->exists(Organization::class, ['id' => $organizationId])) {
            throw ValidationException::withMessages([
                'issuing_organization_id' => 'The selected issuing organization does not exist.',
            ]);
        }
    }

    /**
     * The tern identity (type, number, issue_date) is immutable: the
     * year derives from the issue date, so changing any of them
     * would silently re-identify the norm.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertIdentityIsImmutable(LegalBasis $basis, array $payload): void
    {
        $guards = [
            'legal_basis_type_id' => fn (): string => (string) $basis->legal_basis_type_id,
            'number' => fn (): string => (string) $basis->number,
            'issue_date' => fn (): string => $basis->issue_date->format('Y-m-d'),
        ];

        foreach ($guards as $field => $current) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            if ((string) $payload[$field] !== $current()) {
                throw ValidationException::withMessages([
                    $field => 'The '.$field.' is immutable and cannot be changed after creation (the type-number-year tern identifies the norm).',
                ]);
            }
        }
    }

    /**
     * RN-006 on the resulting dates: the puesta en vigor never
     * precedes the emisión and the derogación never precedes the
     * puesta en vigor. Null derogation means "in force until further
     * notice".
     */
    private function assertDateOrdering(?string $issueDate, ?string $effectiveDate, ?string $derogationDate): void
    {
        if ($issueDate !== null && $effectiveDate !== null
            && new \DateTimeImmutable($effectiveDate) < new \DateTimeImmutable($issueDate)) {
            throw ValidationException::withMessages([
                'effective_date' => 'The effective date must not precede the issue date (RN-006).',
            ]);
        }

        if ($effectiveDate !== null && $derogationDate !== null
            && new \DateTimeImmutable($derogationDate) < new \DateTimeImmutable($effectiveDate)) {
            throw ValidationException::withMessages([
                'derogation_date' => 'The derogation date must not precede the effective date (RN-006).',
            ]);
        }
    }

    /**
     * Date fields present in the payload, normalized to Y-m-d so
     * PATCH semantics merge cleanly with the stored state.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string|null>
     */
    private function resultingDates(array $payload, ?LegalBasis $basis): array
    {
        $dates = [];
        foreach (['issue_date', 'effective_date', 'derogation_date'] as $field) {
            if (array_key_exists($field, $payload)) {
                $value = $payload[$field];
                $dates[$field] = ($value === null || $value === '') ? null : (string) $value;
            } elseif ($basis !== null) {
                $dates[$field] = match ($field) {
                    'issue_date' => $basis->issue_date->format('Y-m-d'),
                    'effective_date' => $basis->effective_date->format('Y-m-d'),
                    default => $basis->derogation_date?->format('Y-m-d'),
                };
            }
        }

        return $dates;
    }

    private function assertTernIsFree(int $typeId, string $number, int $year, ?int $exceptId): void
    {
        if ($this->bases->ternExists($typeId, $number, $year, $exceptId)) {
            throw ValidationException::withMessages([
                'number' => 'A legal basis with this type, number and year already exists (tern is unique, RF-LEG-002).',
            ]);
        }
    }
}
