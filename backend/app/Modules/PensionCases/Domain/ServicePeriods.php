<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Domain;

/**
 * Overlap detection over the declared services (RF-EXP-003, H-15).
 * Two periods overlap when they share at least one day — inclusive
 * bounds — which is exactly the classic interval intersection:
 * a.start <= b.end AND b.start <= a.end. Since the Task 37 user
 * correction every period is closed (mandatory end, strictly after
 * the start) and the outcome is a REJECTION, never a warning: no two
 * subrecords of a case may share time, so the application layer
 * probes both entry points — the nested rows of the creation payload
 * (pairwise, overlappingPairs) and the individual alta against the
 * rows the case already holds (idsOverlappingWith) — answering 422
 * before anything is written. Pairs are reported once with ascending
 * ids so the rejection message is stable and diff-friendly.
 */
final class ServicePeriods
{
    private function __construct() {}

    /**
     * Overlapping id pairs, each once, ids ascending, pairs ordered
     * by first id then second — the probe of the NESTED creation
     * payload, where the rows are simultaneous and no single row owns
     * the fault.
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
     * Ids of the declared services that share at least one day with
     * the candidate period, in the order they were declared — the
     * probe of the INDIVIDUAL alta, where the candidate is the only
     * new row and the returned ids name the stored records the 422
     * message must mention.
     *
     * @param  list<DeclaredService>  $services
     * @return list<int>
     */
    public static function idsOverlappingWith(DeclaredService $candidate, array $services): array
    {
        $overlapping = [];

        foreach ($services as $service) {
            if (self::periodsOverlap($candidate, $service)) {
                $overlapping[] = $service->id;
            }
        }

        return $overlapping;
    }

    /**
     * Inclusive-day interval intersection.
     */
    private static function periodsOverlap(DeclaredService $left, DeclaredService $right): bool
    {
        return $left->startDate <= $right->endDate && $right->startDate <= $left->endDate;
    }

    /** @return array{int, int} */
    private static function ascendingPair(int $a, int $b): array
    {
        return $a < $b ? [$a, $b] : [$b, $a];
    }
}
