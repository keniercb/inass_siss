<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application\Services;

use App\Modules\Catalogs\Application\Contracts\CatalogRepositoryInterface;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\CatalogModel;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Municipality;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\OfficeType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Province;
use App\Modules\Organizations\Application\Contracts\OfficeAssignmentQueryInterface;
use App\Modules\Organizations\Application\Contracts\OfficeCaseCountQueryInterface;
use App\Modules\Organizations\Application\Contracts\OfficeRepositoryInterface;
use App\Modules\Organizations\Application\Contracts\OfficeServiceInterface;
use App\Modules\Organizations\Domain\HierarchyPolicy;
use App\Modules\Organizations\Domain\HierarchyTotals;
use App\Modules\Organizations\Domain\OfficeStructurePolicy;
use App\Modules\Organizations\Infrastructure\Persistence\Models\Office;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Use cases for offices (RF-ENT-002, RF-ENT-005, S4.1-S4.3).
 *
 * Same structural contracts as entities: acyclic hierarchy (RN-003)
 * via the Domain HierarchyPolicy over the active parent map, and
 * geographic coherence (RN-004) validated upfront with the composite
 * database key as the last line. Offices carry no natural key, so
 * there is no uniqueness or immutability guard beyond the
 * territorial one.
 *
 * The territorial structure (ADR-31) rules the NAC/PRO/MUN triad:
 * a single national office, a single provincial per province, a
 * single municipal per province and municipality, the parent chain
 * fixed by the type (provincial -> national, municipal ->
 * provincial of the same province) and the existence prerequisites
 * of the superior offices — all answered as 422 per field before
 * persisting. Types outside the triad keep the generic optional
 * parent of RN-003.
 *
 * The case counts of RF-ENT-005 (second part, ADR-28) cross the
 * module boundary through the OfficeCaseCountQueryInterface port:
 * the projection comes from PensionCases — the data owner — while
 * this service aggregates the ámbito totals with the pure Domain
 * HierarchyTotals over the active snapshot.
 */
final class OfficeService implements OfficeServiceInterface
{
    private const TREE_MAX_DEPTH = 5;

    private const PAYLOAD_COLUMNS = [
        'office_type_id', 'province_id', 'municipality_id', 'address', 'parent_office_id',
    ];

    /**
     * @param  CatalogRepositoryInterface<CatalogModel>  $catalogs
     */
    public function __construct(
        private readonly OfficeRepositoryInterface $offices,
        private readonly CatalogRepositoryInterface $catalogs,
        private readonly OfficeCaseCountQueryInterface $caseCounts,
        private readonly OfficeAssignmentQueryInterface $assignments,
    ) {}

    public function create(array $attributes): Office
    {
        $payload = $this->acceptedPayload($attributes);

        $this->assertMandatoryKeys($payload, ['office_type_id', 'province_id', 'municipality_id', 'address']);
        $this->assertReferencesAreValid($payload);

        $typeCode = $this->typeCodeOf((int) $payload['office_type_id']);

        if (OfficeStructurePolicy::isTerritorialType($typeCode)) {
            $payload = $this->applyStructureRules($typeCode, $payload, $payload, null);
        } else {
            $this->assertParentIsAcceptable(null, $payload['parent_office_id'] ?? null);
        }

        return $this->offices->create($payload);
    }

    public function update(int $id, array $attributes): ?Office
    {
        $office = $this->offices->find($id);

        if ($office === null) {
            return null;
        }

        $payload = $this->acceptedPayload($attributes);

        if ($payload === []) {
            return $office;
        }

        $resulting = array_merge([
            'office_type_id' => $office->office_type_id,
            'province_id' => $office->province_id,
            'municipality_id' => $office->municipality_id,
        ], $payload);

        $this->assertReferencesAreValid($resulting);

        $typeCode = $this->typeCodeOf((int) $resulting['office_type_id']);

        // Children stay coherent for every type: an office with
        // active children cannot move its type or territory out from
        // under them — no retype may demote a parent its children
        // depend on, territorial or not.
        $this->assertChildrenTolerateIdentityChange($office, $resulting);

        if (OfficeStructurePolicy::isTerritorialType($typeCode)) {
            $payload = $this->applyStructureRules($typeCode, $payload, $resulting, $office);
        } elseif (array_key_exists('parent_office_id', $payload)) {
            $this->assertParentIsAcceptable($office, $payload['parent_office_id']);
        }

        return $this->offices->update($office, $payload);
    }

    /**
     * @param  array{q?: string, office_type_id?: int, province_id?: int, municipality_id?: int}  $filters
     * @return LengthAwarePaginator<int, Office>
     */
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->offices->search($filters, $page, $perPage);
    }

    public function get(int $id): ?Office
    {
        return $this->offices->find($id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tree(): array
    {
        $nodes = $this->offices->hierarchyNodes();

        $index = [];
        $childrenOf = [];
        foreach ($nodes as $node) {
            $index[$node->id] = $node;
        }
        foreach ($nodes as $node) {
            if ($node->parent_office_id !== null && isset($index[$node->parent_office_id])) {
                $childrenOf[$node->parent_office_id][] = $node;
            }
        }

        $roots = [];
        foreach ($nodes as $node) {
            if ($node->parent_office_id === null) {
                $roots[] = $node;
            }
        }

        $counters = $this->scopeCounters($nodes);

        return array_map(
            fn (Office $root): array => $this->renderNode($root, $childrenOf, 1, $counters),
            $roots,
        );
    }

    public function caseCountSummary(Office $office): array
    {
        $counters = $this->scopeCounters($this->offices->hierarchyNodes());

        return [
            'cases_count' => $counters['own'][$office->id] ?? 0,
            'scope_cases_count' => $counters['scope'][$office->id] ?? 0,
        ];
    }

    public function delete(int $id): bool
    {
        $office = $this->offices->find($id);

        if ($office === null) {
            return false;
        }

        if ($this->offices->hasActiveChildren($office->id)) {
            throw ValidationException::withMessages([
                'parent_office_id' => 'The office still has active child offices; deactivate them first.',
            ]);
        }

        // Territorial scope guard (ADR-29): an office with active
        // accounts assigned refuses to leave the map — a silent null
        // would strand the users' /auth/me office without a trace.
        $assignedUsers = $this->assignments->countActiveUsers($office->id);

        if ($assignedUsers > 0) {
            throw ValidationException::withMessages([
                'office_id' => "The office still has {$assignedUsers} active users assigned; reassign them first.",
            ]);
        }

        return $this->offices->softDelete($office);
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
     * Existence of type and geography plus the RN-004 coherence, each
     * answering with its own field error.
     *
     * @param  array<string, mixed>  $references
     */
    private function assertReferencesAreValid(array $references): void
    {
        $officeTypeId = $references['office_type_id'] ?? null;

        if ($officeTypeId === null || ! $this->catalogs->exists(OfficeType::class, ['id' => $officeTypeId])) {
            throw ValidationException::withMessages([
                'office_type_id' => 'The selected office type does not exist.',
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
     * The parent must be an active office and re-parenting must stay
     * acyclic (RN-003).
     */
    private function assertParentIsAcceptable(?Office $office, mixed $parentId): void
    {
        if ($parentId === null || $parentId === '') {
            return;
        }

        $parentId = (int) $parentId;

        if ($this->offices->find($parentId) === null) {
            throw ValidationException::withMessages([
                'parent_office_id' => 'The selected parent office does not exist or is deactivated.',
            ]);
        }

        if ($office === null) {
            return;
        }

        $parentMap = [];
        foreach ($this->offices->hierarchyNodes() as $node) {
            $parentMap[$node->id] = $node->parent_office_id;
        }

        if (HierarchyPolicy::wouldCreateCycle($office->id, $parentId, $parentMap)) {
            throw ValidationException::withMessages([
                'parent_office_id' => 'The selected parent would create a cycle in the office hierarchy (RN-003).',
            ]);
        }
    }

    /**
     * Resolves the catalog code of an office type already proven to
     * exist by assertReferencesAreValid: the territorial rules speak
     * in codes, so the catalog stays the single source of truth.
     */
    private function typeCodeOf(int $officeTypeId): string
    {
        $type = $this->catalogs->find(OfficeType::class, $officeTypeId);

        return $type instanceof OfficeType ? (string) $type->code : '';
    }

    /**
     * Territorial structure rules (ADR-31) over the resulting state:
     * per-scope uniqueness among active offices, existence of the
     * required superior and the forced parent. Returns the payload
     * with parent_office_id resolved — omitted or matching values
     * are accepted, contradictions answer 422 on parent_office_id.
     *
     * @param  array<string, mixed>  $payload  the accepted write payload
     * @param  array<string, mixed>  $resulting  the resulting office state (type, province and municipality)
     * @param  Office|null  $current  null on create; the stored office on update
     * @return array<string, mixed>
     */
    private function applyStructureRules(string $typeCode, array $payload, array $resulting, ?Office $current): array
    {
        $exceptId = $current?->id;

        // 1. Per-scope uniqueness (rules 1-3): the lookup excludes the
        //    office being edited, so re-saving itself never conflicts.
        $scope = OfficeStructurePolicy::uniquenessScope(
            $typeCode,
            (int) $resulting['province_id'],
            (int) $resulting['municipality_id'],
        );

        $conflict = $this->offices->findActiveOfType(
            $scope['type_code'],
            $scope['province_id'],
            $scope['municipality_id'],
            $exceptId,
        );

        if ($conflict !== null) {
            throw ValidationException::withMessages([
                'office_type_id' => match ($typeCode) {
                    OfficeStructurePolicy::TYPE_NATIONAL => 'A national office already exists; the country keeps a single national office.',
                    OfficeStructurePolicy::TYPE_PROVINCIAL => 'A provincial office already exists for the selected province; each province keeps a single provincial office.',
                    default => 'A municipal office already exists for the selected municipality; each municipality keeps a single municipal office.',
                },
            ]);
        }

        // 2. Required superior (rules 4-6): provincial offices need
        //    the national one and municipal offices the provincial of
        //    their province; the national office is the root.
        $parentCode = OfficeStructurePolicy::parentTypeCode($typeCode);

        $expectedParentId = null;

        if ($parentCode !== null) {
            $parent = $this->offices->findActiveOfType(
                $parentCode,
                // The municipal parent must be the provincial office
                // of the SAME province (rule 4): narrow the lookup.
                $parentCode === OfficeStructurePolicy::TYPE_PROVINCIAL ? (int) $resulting['province_id'] : null,
                null,
                $exceptId,
            );

            if ($parent === null) {
                throw ValidationException::withMessages([
                    'office_type_id' => $parentCode === OfficeStructurePolicy::TYPE_NATIONAL
                        ? 'The national office must exist before registering provincial offices.'
                        : 'The provincial office of the selected province must exist before registering municipal offices.',
                ]);
            }

            $expectedParentId = $parent->id;
        }

        // 3. The client cannot contradict the derived parent: a
        //    concrete value must match it (rule 4/5) and the national
        //    office cannot carry any parent at all.
        $provided = $payload['parent_office_id'] ?? null;

        if ($provided !== null && $provided !== '' && (int) $provided !== $expectedParentId) {
            throw ValidationException::withMessages([
                'parent_office_id' => match ($typeCode) {
                    OfficeStructurePolicy::TYPE_NATIONAL => 'The national office is the root of the hierarchy and cannot report to another office.',
                    OfficeStructurePolicy::TYPE_PROVINCIAL => 'Provincial offices must report to the national office; omit parent_office_id or send the national office id.',
                    default => 'Municipal offices must report to the provincial office of their province; omit parent_office_id or send that office id.',
                },
            ]);
        }

        // An explicit null on update means unrooting the office: a
        // contradiction for every type but the national root.
        if ($current !== null
            && array_key_exists('parent_office_id', $payload)
            && ($payload['parent_office_id'] === null || $payload['parent_office_id'] === '')
            && $expectedParentId !== null) {
            throw ValidationException::withMessages([
                'parent_office_id' => $parentCode === OfficeStructurePolicy::TYPE_NATIONAL
                    ? 'Provincial offices must report to the national office; omit parent_office_id or send the national office id.'
                    : 'Municipal offices must report to the provincial office of their province; omit parent_office_id or send that office id.',
            ]);
        }

        // 4. The derived parent is the truth written to the database.
        $payload['parent_office_id'] = $expectedParentId;

        return $payload;
    }

    /**
     * Active children stay coherent (ADR-31): an office with active
     * children cannot change its type or territory, because its
     * children depend on what it is — the guard mirrors the
     * deactivation one ("deactivate them first").
     *
     * @param  array{office_type_id: int, province_id: int, municipality_id: int}  $resulting
     */
    private function assertChildrenTolerateIdentityChange(Office $office, array $resulting): void
    {
        foreach (['office_type_id', 'province_id', 'municipality_id'] as $field) {
            if ((int) $resulting[$field] === (int) $office->{$field}) {
                continue;
            }

            if ($this->offices->hasActiveChildren($office->id)) {
                throw ValidationException::withMessages([
                    $field => 'The office still has active child offices; deactivate or relocate them before changing its type or territory.',
                ]);
            }

            return;
        }
    }

    /**
     * Own and scope case counters over the active snapshot: the own
     * map comes from the PensionCases projection and the scope map
     * is the bottom-up aggregation of the ámbito (ADR-28).
     *
     * @param  list<Office>  $nodes
     * @return array{own: array<int, int>, scope: array<int, int>}
     */
    private function scopeCounters(array $nodes): array
    {
        $own = $this->caseCounts->countsByOffice();

        $parentMap = [];
        foreach ($nodes as $node) {
            $parentMap[$node->id] = $node->parent_office_id;
        }

        return [
            'own' => $own,
            'scope' => HierarchyTotals::subtreeTotals($parentMap, $own),
        ];
    }

    /**
     * @param  array<int, list<Office>>  $childrenOf
     * @param  array{own: array<int, int>, scope: array<int, int>}  $counters
     * @return array<string, mixed>
     */
    private function renderNode(Office $node, array $childrenOf, int $depth, array $counters): array
    {
        $children = $childrenOf[$node->id] ?? [];

        $type = $node->officeType;

        $typeSummary = $type === null ? null : ['id' => $type->id, 'code' => $type->code, 'name' => $type->name];

        $counts = [
            'cases_count' => $counters['own'][$node->id] ?? 0,
            'scope_cases_count' => $counters['scope'][$node->id] ?? 0,
        ];

        if ($depth >= self::TREE_MAX_DEPTH) {
            return [
                'id' => $node->id,
                'address' => $node->address,
                'type' => $typeSummary,
                ...$counts,
                'deeper' => $children !== [],
            ];
        }

        return [
            'id' => $node->id,
            'address' => $node->address,
            'type' => $typeSummary,
            ...$counts,
            'children' => array_map(
                fn (Office $child): array => $this->renderNode($child, $childrenOf, $depth + 1, $counters),
                $children,
            ),
        ];
    }
}
