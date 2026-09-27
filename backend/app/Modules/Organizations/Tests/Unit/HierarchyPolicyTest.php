<?php

declare(strict_types=1);

// RN-003 — jerarquías acíclicas: the cycle detector is a pure Domain
// policy (S4.2). The datasets below are the documented trees from the
// development plan: valid hierarchies stay valid and every classic
// cycle shape (self, direct 2-cycle, indirect 3-cycle, deep chain)
// is rejected before persistence. The policy receives the parent map
// as plain data, so the algorithm is provable without any database.

use App\Modules\Organizations\Domain\HierarchyPolicy;

describe('HierarchyPolicy (RN-003)', function () {
    it('accepts a root node (no parent)', function () {
        expect(HierarchyPolicy::wouldCreateCycle(1, null, []))->toBeFalse();
    });

    it('accepts a linear chain', function () {
        // 1 -> 2 -> 3 -> 4 (child -> parent map)
        $parents = [1 => null, 2 => 1, 3 => 2, 4 => 3];

        expect(HierarchyPolicy::wouldCreateCycle(4, 3, $parents))->toBeFalse();
    });

    it('accepts a broad valid tree', function () {
        //        1
        //      / | \
        //     2  3  4
        //    / \     \
        //   5   6     7
        $parents = [
            1 => null,
            2 => 1, 3 => 1, 4 => 1,
            5 => 2, 6 => 2, 7 => 4,
        ];

        foreach ([5, 6, 7] as $node) {
            expect(HierarchyPolicy::wouldCreateCycle($node, $parents[$node], $parents))->toBeFalse();
        }
    });

    it('accepts re-parenting into a different branch', function () {
        // Moving 7 under 3 keeps the tree acyclic.
        $parents = [1 => null, 2 => 1, 3 => 1, 4 => 1, 5 => 2, 6 => 2, 7 => 4];

        expect(HierarchyPolicy::wouldCreateCycle(7, 3, $parents))->toBeFalse();
    });

    it('accepts keeping the current parent (no-op move)', function () {
        $parents = [1 => null, 2 => 1];

        expect(HierarchyPolicy::wouldCreateCycle(2, 1, $parents))->toBeFalse();
    });

    it('rejects a node becoming its own parent', function () {
        expect(HierarchyPolicy::wouldCreateCycle(1, 1, [1 => null]))->toBeTrue();
    });

    it('rejects a direct 2-cycle', function () {
        // 1's parent is 2; making 1 the parent of 2 closes 1 <-> 2.
        $parents = [1 => 2, 2 => null];

        expect(HierarchyPolicy::wouldCreateCycle(2, 1, $parents))->toBeTrue();
    });

    it('rejects an indirect 3-cycle', function () {
        // 1 -> 2 -> 3; making 1 the parent of 3 closes 1 -> 2 -> 3 -> 1.
        $parents = [1 => 2, 2 => 3, 3 => null];

        expect(HierarchyPolicy::wouldCreateCycle(3, 1, $parents))->toBeTrue();
    });

    it('rejects a deep cycle closing six levels up', function () {
        // 6 -> 5 -> 4 -> 3 -> 2 -> 1; making 6 the parent of 1 walks
        // the whole chain back to 6.
        $parents = [1 => null, 2 => 1, 3 => 2, 4 => 3, 5 => 4, 6 => 5];

        expect(HierarchyPolicy::wouldCreateCycle(1, 6, $parents))->toBeTrue();
    });

    it('rejects a cycle regardless of which node moves', function () {
        // Same chain, cycle closes when 4 adopts 1.
        $parents = [1 => null, 2 => 1, 3 => 2, 4 => 3, 5 => 4, 6 => 5];

        expect(HierarchyPolicy::wouldCreateCycle(1, 4, $parents))->toBeTrue();
    });

    it('stops the walk at an unknown parent without crashing', function () {
        // Repository snapshots may contain dangling ids; the walk must
        // terminate, not throw.
        $parents = [1 => 99, 99 => null];

        expect(HierarchyPolicy::wouldCreateCycle(2, 1, $parents))->toBeFalse();
    });

    it('is pure: the caller map is never mutated', function () {
        $parents = [1 => null, 2 => 1];
        $result = HierarchyPolicy::wouldCreateCycle(1, 2, $parents);

        expect($result)->toBeTrue()
            ->and($parents)->toBe([1 => null, 2 => 1]);
    });
});
