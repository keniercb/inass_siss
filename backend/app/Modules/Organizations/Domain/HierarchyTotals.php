<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain;

/**
 * Subtree aggregation over an active hierarchy (RF-ENT-005 second
 * part, ADR-28): the scope («ámbito») of an office is itself plus
 * every active descendant, so the scope total of a node is its own
 * value plus the totals of its children.
 *
 * Same contract as HierarchyPolicy: the algorithm receives the
 * active parent map as plain data (id => parent id, null for roots)
 * and never touches the database, so the datasets in
 * HierarchyTotalsTest prove it as permanent regression assets. A
 * node whose parent is missing from the map (a deactivated parent
 * leaves the active snapshot) aggregates as its own root: nobody
 * above absorbs its subtree, mirroring how the tree renders it.
 */
final class HierarchyTotals
{
    /**
     * Bottom-up totals of every node in the map.
     *
     * @param  array<int, int|null>  $parentMap  active hierarchy snapshot (id => parent id)
     * @param  array<int, int>  $values  own value per node (missing nodes count as zero;
     *                                   values of nodes outside the map are ignored)
     * @return array<int, int> node id => subtree total (own value + descendants)
     */
    public static function subtreeTotals(array $parentMap, array $values): array
    {
        $childrenOf = [];

        foreach ($parentMap as $id => $parentId) {
            // array_key_exists, NOT isset: the parent of a child is a
            // root exactly when its map value is null, and isset()
            // would silently drop every child hanging from a root.
            if ($parentId !== null && array_key_exists($parentId, $parentMap)) {
                $childrenOf[$parentId][] = $id;
            }
        }

        $totals = [];

        $compute = function (int $id) use (&$compute, &$totals, $childrenOf, $values): int {
            if (array_key_exists($id, $totals)) {
                return $totals[$id];
            }

            // Reserve the slot before recursing: the active snapshot
            // is acyclic by induction (RN-003), but a corrupt map
            // must degrade instead of looping forever.
            $totals[$id] = 0;

            $total = $values[$id] ?? 0;

            foreach ($childrenOf[$id] ?? [] as $childId) {
                $total += $compute($childId);
            }

            return $totals[$id] = $total;
        };

        foreach (array_keys($parentMap) as $id) {
            $compute((int) $id);
        }

        // Deterministic key order: the recursion inserts children
        // before their siblings' subtrees, and callers (and their
        // tests) should read the map in id order.
        ksort($totals);

        return $totals;
    }
}
