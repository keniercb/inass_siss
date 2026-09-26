<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Application\Services;

use App\Modules\Catalogs\Application\Contracts\AgencyServiceInterface;
use App\Modules\Catalogs\Application\Contracts\CatalogRepositoryInterface;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Agency;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\AgencyType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Municipality;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Province;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Use cases for bank agencies (RF-CAT-003).
 *
 * Validates the three foreign references plus the geographic
 * coherence rule RN-04 (the municipality must belong to the declared
 * province) before persisting, answering with per-field 422 errors.
 * The database composite foreign key remains the hard guarantee; this
 * service exists so clients receive semantics instead of driver
 * errors.
 */
final class AgencyService implements AgencyServiceInterface
{
    private const PAYLOAD_COLUMNS = ['code', 'name', 'province_id', 'municipality_id', 'agency_type_id'];

    /**
     * @param  CatalogRepositoryInterface<Agency>  $catalogs
     */
    public function __construct(
        private readonly CatalogRepositoryInterface $catalogs,
    ) {}

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
    ): LengthAwarePaginator {
        $filters = array_filter([
            'province_id' => $provinceId,
            'municipality_id' => $municipalityId,
            'agency_type_id' => $agencyTypeId,
        ], static fn ($value): bool => $value !== null);

        return $this->catalogs->paginate(
            Agency::class,
            $search,
            ['name', 'code'],
            $this->resolveSortColumn($sort),
            $order === 'desc',
            $filters,
            ['province', 'municipality', 'agencyType'],
            $page,
            $perPage,
        );
    }

    public function get(int $id): ?Agency
    {
        $model = $this->catalogs->findIncludingDeactivated(Agency::class, $id);

        return $model instanceof Agency ? $model : null;
    }

    public function create(array $attributes): Agency
    {
        $payload = $this->acceptedPayload($attributes);

        $this->assertReferencesAreValid($payload);
        $this->assertCodeIsFree($payload, null);

        $agency = new Agency;
        $agency->fill($payload);
        $this->catalogs->save($agency);

        return $agency;
    }

    public function update(int $id, array $attributes): ?Agency
    {
        // Active-only resolution: deactivated agencies cannot be
        // edited (restore is a later-phase concern).
        $agency = $this->catalogs->find(Agency::class, $id);

        if (! $agency instanceof Agency) {
            return null;
        }

        $payload = $this->acceptedPayload($attributes);

        if ($payload === []) {
            return $agency;
        }

        $this->assertCodeIsImmutable($agency, $payload);

        // References and RN-04 are validated against the resulting
        // state: patched fields merge with the stored values.
        $this->assertReferencesAreValid([
            'province_id' => array_key_exists('province_id', $payload)
                ? $payload['province_id']
                : $agency->province_id,
            'municipality_id' => array_key_exists('municipality_id', $payload)
                ? $payload['municipality_id']
                : $agency->municipality_id,
            'agency_type_id' => array_key_exists('agency_type_id', $payload)
                ? $payload['agency_type_id']
                : $agency->agency_type_id,
        ]);
        $this->assertCodeIsFree($payload, $id);

        $agency->fill($payload);
        $this->catalogs->save($agency);

        return $agency;
    }

    public function deactivate(int $id): bool
    {
        $agency = $this->catalogs->find(Agency::class, $id);

        if (! $agency instanceof Agency) {
            return false;
        }

        // Agencies have no dependents until Phase 5 wires bank
        // controls; the guard hooks stay in place for that day.
        $this->catalogs->deactivate($agency);

        return true;
    }

    private function resolveSortColumn(?string $sort): string
    {
        return match ($sort) {
            'id' => 'id',
            'code' => 'code',
            default => 'name',
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|int|null>
     */
    private function acceptedPayload(array $attributes): array
    {
        $accepted = array_flip(self::PAYLOAD_COLUMNS);

        /** @var array<string, string|int|null> */
        return array_intersect_key($attributes, $accepted);
    }

    /**
     * Existence of the three references plus the RN-04 coherence
     * rule, each answering with its own field error.
     *
     * @param  array<string, string|int|null>  $references
     */
    private function assertReferencesAreValid(array $references): void
    {
        $provinceId = $references['province_id'] ?? null;

        if ($provinceId === null || ! $this->catalogs->exists(Province::class, ['id' => $provinceId])) {
            throw ValidationException::withMessages([
                'province_id' => 'The selected province does not exist.',
            ]);
        }

        $agencyTypeId = $references['agency_type_id'] ?? null;

        if ($agencyTypeId === null || ! $this->catalogs->exists(AgencyType::class, ['id' => $agencyTypeId])) {
            throw ValidationException::withMessages([
                'agency_type_id' => 'The selected agency type does not exist.',
            ]);
        }

        $municipalityId = $references['municipality_id'] ?? null;

        if ($municipalityId === null) {
            throw ValidationException::withMessages([
                'municipality_id' => 'The selected municipality does not exist.',
            ]);
        }

        $municipality = $this->catalogs->find(Municipality::class, (int) $municipalityId);

        if (! $municipality instanceof Municipality) {
            throw ValidationException::withMessages([
                'municipality_id' => 'The selected municipality does not exist.',
            ]);
        }

        if ($municipality->province_id !== $provinceId) {
            throw ValidationException::withMessages([
                'municipality_id' => 'The selected municipality does not belong to the declared province (RN-004).',
            ]);
        }
    }

    /**
     * @param  array<string, string|int|null>  $payload
     */
    private function assertCodeIsFree(array $payload, ?int $exceptId): void
    {
        if (! isset($payload['code'])) {
            return;
        }

        if ($this->catalogs->existsAny(Agency::class, ['code' => $payload['code']], $exceptId)) {
            throw ValidationException::withMessages([
                'code' => 'The code is already in use.',
            ]);
        }
    }

    /**
     * @param  array<string, string|int|null>  $payload
     */
    private function assertCodeIsImmutable(Agency $agency, array $payload): void
    {
        if (! array_key_exists('code', $payload)) {
            return;
        }

        if ($payload['code'] !== $agency->code) {
            throw ValidationException::withMessages([
                'code' => 'The code is immutable and cannot be changed after creation.',
            ]);
        }
    }
}
