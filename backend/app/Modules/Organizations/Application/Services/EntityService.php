<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application\Services;

use App\Modules\Catalogs\Application\Contracts\CatalogRepositoryInterface;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\CatalogModel;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\EntityType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Municipality;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Organization;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Province;
use App\Modules\Organizations\Application\Contracts\EntityRepositoryInterface;
use App\Modules\Organizations\Application\Contracts\EntityServiceInterface;
use App\Modules\Organizations\Domain\HierarchyPolicy;
use App\Modules\Organizations\Infrastructure\Persistence\Models\Entity;
use App\Modules\People\Application\Contracts\PeopleRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Use cases for entities (RF-ENT-001, RF-ENT-005, S4.1-S4.3).
 *
 * The structural rules are inviolable and land here before persisting:
 * RN-003 — the hierarchy is kept acyclic by the pure Domain
 * HierarchyPolicy fed with the active parent map, because no database
 * constraint can express "no cycles"; RN-004 — the municipality must
 * belong to the declared province, with the composite database key as
 * the last line (mirrors the AgencyService semantics). Code and NIT
 * are unique including deactivated rows and immutable after creation.
 * The Catalogs and People modules are consulted through their public
 * ports (deptrac: Organizations depends on Shared, Catalogs, People).
 */
final class EntityService implements EntityServiceInterface
{
    private const PAYLOAD_COLUMNS = [
        'code', 'tax_id_number', 'organization_id', 'province_id', 'municipality_id',
        'entity_type_id', 'address', 'phone', 'fax', 'email',
        'director_person_id', 'economic_director_person_id', 'parent_entity_id',
        'social_purpose',
    ];

    /**
     * @param  CatalogRepositoryInterface<CatalogModel>  $catalogs
     */
    public function __construct(
        private readonly EntityRepositoryInterface $entities,
        private readonly CatalogRepositoryInterface $catalogs,
        private readonly PeopleRepositoryInterface $people,
    ) {}

    public function create(array $attributes): Entity
    {
        $payload = $this->acceptedPayload($attributes);

        $this->assertMandatoryKeys($payload, ['code', 'tax_id_number', 'organization_id', 'province_id', 'municipality_id', 'entity_type_id', 'address', 'social_purpose']);
        $this->assertReferencesAreValid($payload);
        $this->assertDirectorsExist($payload);
        $this->assertNaturalKeysAreFree($payload, null);
        $this->assertParentIsAcceptable(null, $payload['parent_entity_id'] ?? null);

        return $this->entities->create($payload);
    }

    public function update(int $id, array $attributes): ?Entity
    {
        $entity = $this->entities->find($id);

        if ($entity === null) {
            return null;
        }

        $payload = $this->acceptedPayload($attributes);

        if ($payload === []) {
            return $entity;
        }

        $this->assertNaturalKeysAreImmutable($entity, $payload);

        // References, coherence and hierarchy are validated against
        // the resulting state: patched fields merge with stored ones.
        $resulting = array_merge([
            'organization_id' => $entity->organization_id,
            'province_id' => $entity->province_id,
            'municipality_id' => $entity->municipality_id,
            'entity_type_id' => $entity->entity_type_id,
            'director_person_id' => $entity->director_person_id,
            'economic_director_person_id' => $entity->economic_director_person_id,
        ], $payload);

        $this->assertReferencesAreValid($resulting);
        $this->assertDirectorsExist($resulting);

        if (array_key_exists('parent_entity_id', $payload)) {
            $this->assertParentIsAcceptable($entity, $payload['parent_entity_id']);
        }

        return $this->entities->update($entity, $payload);
    }

    /**
     * @param  array{q?: string, organization_id?: int, province_id?: int, municipality_id?: int, entity_type_id?: int}  $filters
     * @return LengthAwarePaginator<int, Entity>
     */
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->entities->search($filters, $page, $perPage);
    }

    public function get(int $id): ?Entity
    {
        return $this->entities->find($id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tree(): array
    {
        $nodes = $this->entities->hierarchyNodes();

        [$index, $childrenOf] = $this->groupHierarchy($nodes);

        // Roots render in id order; a node whose parent is missing
        // from the active snapshot (deactivated superior) is dropped:
        // the service guard makes that state unreachable through the
        // API, and the tree never silently re-roots a detached
        // subtree.
        $roots = [];
        foreach ($nodes as $node) {
            if ($node->parent_entity_id === null) {
                $roots[] = $node;
            }
        }

        return array_map(
            fn (Entity $root): array => $this->renderNode($root, $childrenOf, 1),
            $roots,
        );
    }

    public function delete(int $id): bool
    {
        $entity = $this->entities->find($id);

        if ($entity === null) {
            return false;
        }

        if ($this->entities->hasActiveChildren($entity->id)) {
            throw ValidationException::withMessages([
                'parent_entity_id' => 'The entity still has active child entities; deactivate them first.',
            ]);
        }

        return $this->entities->softDelete($entity);
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
     * Store answers 422 through the FormRequest; this guard covers
     * service-level callers (imports, future commands).
     *
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
     * Existence of the organizational, geographic and typological
     * references plus the RN-004 coherence, each answering with its
     * own field error (same semantics as agencies, RF-CAT-003).
     *
     * @param  array<string, mixed>  $references
     */
    private function assertReferencesAreValid(array $references): void
    {
        $organizationId = $references['organization_id'] ?? null;

        if ($organizationId === null || ! $this->catalogs->exists(Organization::class, ['id' => $organizationId])) {
            throw ValidationException::withMessages([
                'organization_id' => 'The selected organization does not exist.',
            ]);
        }

        $entityTypeId = $references['entity_type_id'] ?? null;

        if ($entityTypeId === null || ! $this->catalogs->exists(EntityType::class, ['id' => $entityTypeId])) {
            throw ValidationException::withMessages([
                'entity_type_id' => 'The selected entity type does not exist.',
            ]);
        }

        $provinceId = $references['province_id'] ?? null;

        if ($provinceId === null || ! $this->catalogs->exists(Province::class, ['id' => $provinceId])) {
            throw ValidationException::withMessages([
                'province_id' => 'The selected province does not exist.',
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
     * Directors reference registered people (RF-ENT-001); deactivated
     * people still count as registered.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertDirectorsExist(array $payload): void
    {
        foreach (['director_person_id', 'economic_director_person_id'] as $field) {
            $personId = $payload[$field] ?? null;

            if ($personId === null) {
                continue;
            }

            if ($this->people->findByIdIncludingDeactivated((int) $personId) === null) {
                throw ValidationException::withMessages([
                    $field => 'The selected person does not exist.',
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertNaturalKeysAreFree(array $payload, ?int $exceptId): void
    {
        $errors = [];

        if (isset($payload['code']) && $this->entities->existsByCode((string) $payload['code'], $exceptId)) {
            $errors['code'] = ['The code is already in use.'];
        }

        if (isset($payload['tax_id_number']) && $this->entities->existsByTaxIdNumber((string) $payload['tax_id_number'], $exceptId)) {
            $errors['tax_id_number'] = ['The tax id number is already in use.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertNaturalKeysAreImmutable(Entity $entity, array $payload): void
    {
        foreach (['code', 'tax_id_number'] as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            if ($payload[$field] !== $entity->{$field}) {
                throw ValidationException::withMessages([
                    $field => 'The '.$field.' is immutable and cannot be changed after creation.',
                ]);
            }
        }
    }

    /**
     * The parent must be an active entity, and re-parenting must keep
     * the hierarchy acyclic (RN-003): the Domain policy walks the
     * active parent map from the candidate parent upwards.
     */
    private function assertParentIsAcceptable(?Entity $entity, mixed $parentId): void
    {
        if ($parentId === null || $parentId === '') {
            return;
        }

        $parentId = (int) $parentId;

        $parent = $this->entities->find($parentId);

        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_entity_id' => 'The selected parent entity does not exist or is deactivated.',
            ]);
        }

        if ($entity === null) {
            return; // creation: a brand new node cannot close a cycle
        }

        $parentMap = [];
        foreach ($this->entities->hierarchyNodes() as $node) {
            $parentMap[$node->id] = $node->parent_entity_id;
        }

        if (HierarchyPolicy::wouldCreateCycle($entity->id, $parentId, $parentMap)) {
            throw ValidationException::withMessages([
                'parent_entity_id' => 'The selected parent would create a cycle in the entity hierarchy (RN-003).',
            ]);
        }
    }

    /**
     * Groups the active snapshot as (id index, parent => children).
     *
     * @param  list<Entity>  $nodes
     * @return array{0: array<int, Entity>, 1: array<int, list<Entity>>}
     */
    private function groupHierarchy(array $nodes): array
    {
        $index = [];
        $childrenOf = [];

        foreach ($nodes as $node) {
            $index[$node->id] = $node;
        }

        foreach ($nodes as $node) {
            if ($node->parent_entity_id !== null && isset($index[$node->parent_entity_id])) {
                $childrenOf[$node->parent_entity_id][] = $node;
            }
        }

        return [$index, $childrenOf];
    }

    /**
     * @param  array<int, list<Entity>>  $childrenOf
     * @return array<string, mixed>
     */
    private function renderNode(Entity $node, array $childrenOf, int $depth): array
    {
        $children = $childrenOf[$node->id] ?? [];

        if ($depth >= self::TREE_MAX_DEPTH) {
            return [
                'id' => $node->id,
                'code' => $node->code,
                'tax_id_number' => $node->tax_id_number,
                'social_purpose' => $node->social_purpose,
                // The cut is announced, never silent (RF-ENT-005).
                'deeper' => $children !== [],
            ];
        }

        return [
            'id' => $node->id,
            'code' => $node->code,
            'tax_id_number' => $node->tax_id_number,
            'social_purpose' => $node->social_purpose,
            'children' => array_map(
                fn (Entity $child): array => $this->renderNode($child, $childrenOf, $depth + 1),
                $children,
            ),
        ];
    }
}
