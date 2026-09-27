<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain;

/**
 * Acyclic hierarchy rule (RN-003, S4.2): entities and offices form
 * self-referenced trees and no re-parenting may close a cycle.
 *
 * The policy is a pure algorithm — it receives the parent map as
 * plain data (id => parent id, null for roots), walks from the
 * candidate parent up to the root and answers whether the walked
 * chain reaches the moving node. The database cannot express this
 * constraint, so the plan mandates the check in Domain, executed
 * before persisting; the datasets that prove it live in
 * HierarchyPolicyTest and are permanent regression assets.
 */
final class HierarchyPolicy
{
    /**
     * Whether making $newParentId the parent of $nodeId would close a
     * cycle in the hierarchy described by $parentMap.
     *
     * The walk terminates on null (root), on an id missing from the
     * map (dangling snapshot row) or after scanning the whole chain:
     * cycles can only exist if one is being created here, because the
     * stored tree is acyclic by induction.
     *
     * @param  array<int, int|null>  $parentMap
     */
    public static function wouldCreateCycle(int $nodeId, ?int $newParentId, array $parentMap): bool
    {
        if ($newParentId === null) {
            return false;
        }

        if ($newParentId === $nodeId) {
            return true;
        }

        $visited = [];
        $cursor = $newParentId;

        while ($cursor !== null) {
            // Guard against a corrupt stored map: stop instead of
            // looping forever on data that already contains a cycle.
            if (isset($visited[$cursor])) {
                return true;
            }

            $visited[$cursor] = true;

            if ($cursor === $nodeId) {
                return true;
            }

            $cursor = $parentMap[$cursor] ?? null;
        }

        return false;
    }
}
