<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Domain;

/**
 * Overlap and open-link detection over the declared services
 * (RF-EXP-003, H-15). Two periods overlap when they share at least
 * one day — inclusive bounds — which is exactly the classic
 * interval intersection: a.start <= b.end AND b.start <= a.end. An
 * open link (end NULL) stretches to infinity, so it overlaps
 * anything starting on or after its own start.
 *
 * The outcome is a WARNING, never a rejection: the requirement says
 * the system "detects and advertises", leaving the judgement to the
 * specialist. Pairs are reported once with ascending ids so the API
 * response is stable and diff-friendly.
 */
final class ServicePeriods
{
    private function __construct() {}

    /**
     * Overlapping id pairs, each once, ids ascending, pairs ordered
     * by first id then second.
     *
     * @param  list<DeclaredService>  $services
     * @return list<array{int, int}>
     */
    public static function overlappingPairs(array $services): array
    {
        $pairs = [];

        foreach ($services as $i => $left) {
            foreach ($services as $j => $right) {
                if ($i >= $j) {
                    continue;
                }

                if (self::periodsOverlap($left, $right)) {
                    $pairs[] = self::ascendingPair($left->id, $right->id);
                }
            }
        }

        usort($pairs, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        return $pairs;
    }

    /**
     * Ids of the services still without an end date, in the order
     * they were declared.
     *
     * @param  list<DeclaredService>  $services
     * @return list<int>
     */
    public static function openServiceIds(array $services): array
    {
        $open = [];

        foreach ($services as $service) {
            if ($service->endDate === null) {
                $open[] = $service->id;
            }
        }

        return $open;
    }

    private static function periodsOverlap(DeclaredService $left, DeclaredService $right): bool
    {
        $leftEnd = $left->endDate ?? '9999-12-31';
        $rightEnd = $right->endDate ?? '9999-12-31';

        // Inclusive-day interval intersection with open links
        // extended to the end of time.
        return $left->startDate <= $rightEnd && $right->startDate <= $leftEnd;
    }

    /** @return array{int, int} */
    private static function ascendingPair(int $a, int $b): array
    {
        return $a < $b ? [$a, $b] : [$b, $a];
    }
}
