<?php

declare(strict_types=1);

// RF-ENT-005 (segunda parte) — conteo de expedientes por oficina «en
// su ámbito»: the scope count of an office is the value of its own
// subtree (itself + every ACTIVE descendant), aggregated bottom-up
// over the parent map of active offices. The aggregation is a pure
// Domain algorithm over plain data — same style as HierarchyPolicy —
// so the datasets below prove it without any database: chains, broad
// trees, disconnected roots, offices without cases and dangling
// parent references (a deactivated parent leaves the active map, so
// its children stop contributing to anyone's scope).

use App\Modules\Organizations\Domain\HierarchyTotals;

describe('HierarchyTotals (ámbito de oficina)', function () {
    it('returns no totals for an empty hierarchy', function () {
        expect(HierarchyTotals::subtreeTotals([], []))->toBe([]);
    });

    it('totals a lonely root as its own value', function () {
        $parents = [1 => null];

        expect(HierarchyTotals::subtreeTotals($parents, [1 => 7]))->toBe([1 => 7]);
    });

    it('totals a chain bottom-up', function () {
        // 1 -> 2 -> 3 -> 4 (child -> parent map); 2 cases in 1, 3 in 4.
        $parents = [1 => null, 2 => 1, 3 => 2, 4 => 3];
        $values = [1 => 2, 4 => 3];

        expect(HierarchyTotals::subtreeTotals($parents, $values))->toBe([
            1 => 5, 2 => 3, 3 => 3, 4 => 3,
        ]);
    });

    it('totals a broad tree with several branches', function () {
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
        $values = [1 => 1, 2 => 2, 3 => 4, 4 => 8, 5 => 16, 6 => 32, 7 => 64];

        expect(HierarchyTotals::subtreeTotals($parents, $values))->toBe([
            1 => 127,
            2 => 50,
            3 => 4,
            4 => 72,
            5 => 16,
            6 => 32,
            7 => 64,
        ]);
    });

    it('treats every root of a forest independently', function () {
        $parents = [1 => null, 2 => null, 3 => 2];
        $values = [1 => 10, 3 => 5];

        expect(HierarchyTotals::subtreeTotals($parents, $values))->toBe([
            1 => 10, 2 => 5, 3 => 5,
        ]);
    });

    it('counts offices without cases as zero and keeps them in scope', function () {
        $parents = [1 => null, 2 => 1];
        $values = [2 => 9];

        expect(HierarchyTotals::subtreeTotals($parents, $values))->toBe([
            1 => 9, 2 => 9,
        ]);
    });

    it('isolates subtrees below a dangling parent reference', function () {
        // 2 points at 9, which is NOT in the active map (deactivated
        // parent): 2 renders as its own root and nobody absorbs its
        // cases from above.
        $parents = [1 => null, 2 => 9, 3 => 2];
        $values = [2 => 4, 3 => 1];

        expect(HierarchyTotals::subtreeTotals($parents, $values))->toBe([
            1 => 0, 2 => 5, 3 => 1,
        ]);
    });

    it('ignores values of offices outside the active map', function () {
        // 9 is deactivated: its 12 cases belong to nobody's scope.
        $parents = [1 => null];
        $values = [1 => 3, 9 => 12];

        expect(HierarchyTotals::subtreeTotals($parents, $values))->toBe([1 => 3]);
    });
});
