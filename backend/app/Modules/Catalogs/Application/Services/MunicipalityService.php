<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Application\Services;

use App\Modules\Catalogs\Application\Contracts\CatalogRepositoryInterface;
use App\Modules\Catalogs\Application\Contracts\MunicipalityServiceInterface;
use App\Modules\Catalogs\Application\Exceptions\CatalogHasActiveReferencesException;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Agency;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Municipality;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Province;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Use cases for the municipalities catalog (RF-CAT-002).
 *
 * The composite natural key (province_id, code) is probed in the
 * application to answer 422 semantics; the database constraint
 * remains the last line of defense (RN-008). The nullable province
 * models the special municipality Isla de la Juventud.
 */
final class MunicipalityService implements MunicipalityServiceInterface
{
    private const PAYLOAD_COLUMNS = ['province_id', 'code', 'name'];

    /**
     * @param  CatalogRepositoryInterface<Municipality>  $catalogs
     */
    public function __construct(
        private readonly CatalogRepositoryInterface $catalogs,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Municipality>
     */
    public function list(
        ?int $provinceId,
        ?string $search,
        ?string $sort,
        string $order,
        int $page,
        int $perPage,
    ): LengthAwarePaginator {
        $filters = [];

        if ($provinceId !== null) {
            $filters['province_id'] = $provinceId;
        }

        return $this->catalogs->paginate(
            Municipality::class,
            $search,
            ['name', 'code'],
            $this->resolveSortColumn($sort),
            $order === 'desc',
            $filters,
            ['province'],
            $page,
            $perPage,
        );
    }

    public function get(int $id): ?Municipality
    {
        $model = $this->catalogs->findIncludingDeactivated(Municipality::class, $id);

        return $model instanceof Municipality ? $model : null;
    }

    public function create(array $attributes): Municipality
    {
        $payload = $this->acceptedPayload($attributes);

        $this->assertProvinceExists($payload);
        $this->assertCompositeKeyIsFree($payload, null);

        $municipality = new Municipality;
        $municipality->fill($payload);
        $this->catalogs->save($municipality);

        return $municipality;
    }

    public function update(int $id, array $attributes): ?Municipality
    {
        // Active-only resolution: deactivated municipalities cannot be
        // edited (restore is a later-phase concern).
        $municipality = $this->catalogs->find(Municipality::class, $id);

        if (! $municipality instanceof Municipality) {
            return null;
        }

        $payload = $this->acceptedPayload($attributes);

        if ($payload === []) {
            return $municipality;
        }

        $this->assertCodeIsImmutable($municipality, $payload);
        $this->assertProvinceExists($payload);

        // Uniqueness runs against the resulting state, not only the
        // patched fields: an unchanged code must not false-positive.
        $this->assertCompositeKeyIsFree([
            'province_id' => array_key_exists('province_id', $payload)
                ? $payload['province_id']
                : $municipality->province_id,
            'code' => array_key_exists('code', $payload)
                ? $payload['code']
                : $municipality->code,
        ], $id);

        $municipality->fill($payload);
        $this->catalogs->save($municipality);

        return $municipality;
    }

    public function deactivate(int $id): bool
    {
        $municipality = $this->catalogs->find(Municipality::class, $id);

        if (! $municipality instanceof Municipality) {
            return false;
        }

        if ($this->catalogs->hasActiveReferences(Agency::class, 'municipality_id', $id)) {
            throw new CatalogHasActiveReferencesException(['agencies']);
        }

        $this->catalogs->deactivate($municipality);

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
     * @param  array<string, string|int|null>  $payload
     */
    private function assertProvinceExists(array $payload): void
    {
        if (! array_key_exists('province_id', $payload)) {
            return;
        }

        $provinceId = $payload['province_id'];

        // Null is valid: the special municipality has no province.
        if ($provinceId === null) {
            return;
        }

        if (! $this->catalogs->exists(Province::class, ['id' => $provinceId])) {
            throw ValidationException::withMessages([
                'province_id' => 'The selected province does not exist.',
            ]);
        }
    }

    /**
     * Composite natural key (RF-CAT-002): the code is unique within
     * the declared province; two provinces may share codes.
     *
     * @param  array<string, string|int|null>  $payload
     */
    private function assertCompositeKeyIsFree(array $payload, ?int $exceptId): void
    {
        $code = $payload['code'] ?? null;

        if ($code === null) {
            return;
        }

        $provinceId = $payload['province_id'] ?? null;

        if ($this->catalogs->existsAny(
            Municipality::class,
            ['province_id' => $provinceId, 'code' => $code],
            $exceptId,
        )) {
            throw ValidationException::withMessages([
                'code' => 'The code is already in use for the declared province.',
            ]);
        }
    }

    /**
     * The municipality code is as stable as the catalog codes: a
     * public integration identifier that never changes (RF-CAT-001
     * philosophy applied to the composite key).
     *
     * @param  array<string, string|int|null>  $payload
     */
    private function assertCodeIsImmutable(Municipality $municipality, array $payload): void
    {
        if (! array_key_exists('code', $payload)) {
            return;
        }

        if ($payload['code'] !== $municipality->code) {
            throw ValidationException::withMessages([
                'code' => 'The code is immutable and cannot be changed after creation.',
            ]);
        }
    }
}
