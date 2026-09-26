<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Application\Services;

use App\Modules\Catalogs\Application\CatalogRegistry;
use App\Modules\Catalogs\Application\Contracts\CatalogRepositoryInterface;
use App\Modules\Catalogs\Application\Contracts\CatalogServiceInterface;
use App\Modules\Catalogs\Application\DTO\CatalogDefinition;
use App\Modules\Catalogs\Application\Exceptions\CatalogHasActiveReferencesException;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\CatalogModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Generic use cases for the uniform catalogs (RF-CAT-001, ADR-15).
 *
 * Encapsulates the invariants every catalog shares: natural keys are
 * unique (RN-008, probed before insert to answer with a semantic
 * 422 instead of a driver error), the code is immutable after
 * creation (stable integration identifier), and deactivation is
 * blocked while active references exist (RF-CAT-001). Formatting
 * rules live in the request layer; these business rules live here so
 * they are testable without HTTP.
 */
final class CatalogService implements CatalogServiceInterface
{
    /**
     * @param  CatalogRepositoryInterface<CatalogModel>  $catalogs
     */
    public function __construct(
        private readonly CatalogRepositoryInterface $catalogs,
    ) {}

    /**
     * @return LengthAwarePaginator<int, CatalogModel>
     */
    public function list(
        string $type,
        ?string $search,
        ?string $sort,
        string $order,
        int $page,
        int $perPage,
    ): LengthAwarePaginator {
        $definition = CatalogRegistry::definition($type);

        return $this->catalogs->paginate(
            $definition->model,
            $search,
            $definition->hasCode ? ['name', 'code'] : ['name'],
            $this->resolveSortColumn($definition, $sort),
            $order === 'desc',
            [],
            [],
            $page,
            $perPage,
        );
    }

    public function get(string $type, int $id): ?CatalogModel
    {
        $definition = CatalogRegistry::definition($type);

        return $this->catalogs->findIncludingDeactivated($definition->model, $id);
    }

    public function create(string $type, array $attributes): CatalogModel
    {
        $definition = CatalogRegistry::definition($type);
        $payload = $this->acceptedPayload($definition, $attributes);

        $this->assertNaturalKeysAreFree($definition, $payload, null);

        $modelClass = $definition->model;
        $model = new $modelClass;
        $model->fill($payload);
        $this->catalogs->save($model);

        return $model;
    }

    public function update(string $type, int $id, array $attributes): ?CatalogModel
    {
        $definition = CatalogRegistry::definition($type);
        $model = $this->catalogs->find($definition->model, $id);

        if ($model === null) {
            return null;
        }

        $payload = $this->acceptedPayload($definition, $attributes);

        if ($payload === []) {
            return $model;
        }

        $this->assertCodeIsImmutable($definition, $model, $payload);
        $this->assertNaturalKeysAreFree($definition, $payload, $id);

        $model->fill($payload);
        $this->catalogs->save($model);

        return $model;
    }

    public function deactivate(string $type, int $id): bool
    {
        $definition = CatalogRegistry::definition($type);
        $model = $this->catalogs->find($definition->model, $id);

        if ($model === null) {
            return false;
        }

        $references = [];

        foreach ($definition->dependents as $dependentModel => $foreignKey) {
            /** @var class-string<CatalogModel> $dependentModel */
            if ($this->catalogs->hasActiveReferences($dependentModel, $foreignKey, $id)) {
                $references[] = $this->dependentLabel($dependentModel);
            }
        }

        if ($references !== []) {
            throw new CatalogHasActiveReferencesException($references);
        }

        $this->catalogs->deactivate($model);

        return true;
    }

    /**
     * Sort whitelist: unknown or non-existent columns fall back to
     * the default ordering (RF-CAT-006 keeps the API forgiving).
     */
    private function resolveSortColumn(CatalogDefinition $definition, ?string $sort): string
    {
        if ($sort === 'id') {
            return 'id';
        }

        if ($sort === 'code' && $definition->hasCode) {
            return 'code';
        }

        return 'name';
    }

    /**
     * Keeps only the columns the definition accepts, so unknown
     * payload keys never reach mass assignment.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|int|null>
     */
    private function acceptedPayload(CatalogDefinition $definition, array $attributes): array
    {
        $accepted = array_flip($definition->fillableColumns());

        /** @var array<string, string|int|null> */
        return array_intersect_key($attributes, $accepted);
    }

    /**
     * RN-008 application-side mirror: probes both natural keys and
     * answers with a per-field 422 instead of a driver error. The
     * probe includes deactivated rows because the database unique
     * indexes cover them all.
     *
     * @param  array<string, string|int|null>  $payload
     */
    private function assertNaturalKeysAreFree(CatalogDefinition $definition, array $payload, ?int $exceptId): void
    {
        if ($definition->hasCode && isset($payload['code'])
            && $this->catalogs->existsAny($definition->model, ['code' => $payload['code']], $exceptId)) {
            throw ValidationException::withMessages([
                'code' => 'The code is already in use.',
            ]);
        }

        if (isset($payload['name'])
            && $this->catalogs->existsAny($definition->model, ['name' => $payload['name']], $exceptId)) {
            throw ValidationException::withMessages([
                'name' => 'The name is already in use.',
            ]);
        }
    }

    /**
     * RF-CAT-001: the code is a stable integration identifier and can
     * never change after creation; sending the same value is a no-op.
     *
     * @param  array<string, string|int|null>  $payload
     */
    private function assertCodeIsImmutable(CatalogDefinition $definition, CatalogModel $model, array $payload): void
    {
        if (! $definition->hasCode || ! array_key_exists('code', $payload)) {
            return;
        }

        if ($payload['code'] !== $model->getAttribute('code')) {
            throw ValidationException::withMessages([
                'code' => 'The code is immutable and cannot be changed after creation.',
            ]);
        }
    }

    /**
     * Human-readable label for a dependent model class-string
     * ("Municipality" -> "municipalities").
     */
    private function dependentLabel(string $dependentModel): string
    {
        $name = strtolower(substr($dependentModel, (int) strrpos($dependentModel, '\\') + 1));

        return str_ends_with($name, 'y')
            ? substr($name, 0, -1).'ies'
            : $name.'s';
    }
}
