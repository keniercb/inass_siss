<?php

declare(strict_types=1);

// ServicePeriods (RF-EXP-003, H-15; regla de usuario Task 37):
// detección de solapamientos como análisis puro de los períodos
// declarados. Solapar es compartir al menos un día — dos intervalos
// se cruzan cuando cada uno empieza antes de que el otro termine
// (límites inclusivos). Desde la Task 37 el resultado alimenta un
// RECHAZO (422), no una advertencia: ningún par de subregistros del
// expediente puede compartir tiempo, y todo vínculo está cerrado (fin
// obligatorio y posterior al inicio), de modo que el dominio ya no
// conoce vínculos abiertos.

namespace App\Modules\PensionCases\Tests\Unit;

use App\Modules\PensionCases\Domain\DeclaredService;
use App\Modules\PensionCases\Domain\ServicePeriods;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServicePeriods::class)]
#[CoversClass(DeclaredService::class)]
final class ServicePeriodsTest extends TestCase
{
    public function test_disjoint_periods_do_not_overlap(): void
    {
        self::assertSame(
            [],
            ServicePeriods::overlappingPairs([
                new DeclaredService(1, '2000-01-01', '2004-12-31'),
                new DeclaredService(2, '2005-01-01', '2009-12-31'),
            ]),
        );
    }

    public function test_adjacent_periods_do_not_overlap(): void
    {
        // 2004-12-31 → 2005-01-01: the day after the end starts clean.
        self::assertSame(
            [],
            ServicePeriods::overlappingPairs([
                new DeclaredService(1, '2000-01-01', '2004-12-31'),
                new DeclaredService(2, '2005-01-01', '2009-12-31'),
                new DeclaredService(3, '2010-01-01', '2015-12-31'),
            ]),
        );
    }

    public function test_partial_cross_is_an_overlap(): void
    {
        self::assertSame(
            [[1, 2]],
            ServicePeriods::overlappingPairs([
                new DeclaredService(1, '2000-01-01', '2005-12-31'),
                new DeclaredService(2, '2004-06-01', '2008-12-31'),
            ]),
        );
    }

    public function test_one_day_of_shared_time_is_an_overlap(): void
    {
        // Inclusive days: 2005-01-01 belongs to both.
        self::assertSame(
            [[1, 2]],
            ServicePeriods::overlappingPairs([
                new DeclaredService(1, '2000-01-01', '2005-01-01'),
                new DeclaredService(2, '2005-01-01', '2008-12-31'),
            ]),
        );
    }

    public function test_reports_every_pair_once_with_ascending_ids(): void
    {
        // Three mutually overlapping periods: (1,2), (1,3), (2,3).
        self::assertSame(
            [[1, 2], [1, 3], [2, 3]],
            ServicePeriods::overlappingPairs([
                new DeclaredService(3, '2000-01-01', '2009-12-31'),
                new DeclaredService(1, '2001-01-01', '2010-12-31'),
                new DeclaredService(2, '2002-01-01', '2011-12-31'),
            ]),
        );
    }

    public function test_containment_is_an_overlap(): void
    {
        self::assertSame(
            [[1, 2]],
            ServicePeriods::overlappingPairs([
                new DeclaredService(1, '2000-01-01', '2020-12-31'),
                new DeclaredService(2, '2005-01-01', '2010-12-31'),
            ]),
        );
    }

    /**
     * Task 37: the individual alta probes the CANDIDATE period
     * against the services the case already holds — the ids it gets
     * back are the rows the 422 message must name.
     */
    public function test_collects_the_ids_overlapping_a_candidate(): void
    {
        $candidate = new DeclaredService(0, '2006-01-01', '2012-12-31');

        self::assertSame(
            [1, 3],
            ServicePeriods::idsOverlappingWith($candidate, [
                new DeclaredService(1, '2000-01-01', '2007-12-31'),
                new DeclaredService(2, '2001-01-01', '2005-12-31'),
                new DeclaredService(3, '2010-06-01', '2015-12-31'),
            ]),
        );
    }

    public function test_a_candidate_disjoint_from_everything_overlaps_nothing(): void
    {
        $candidate = new DeclaredService(0, '2020-01-01', '2024-12-31');

        self::assertSame(
            [],
            ServicePeriods::idsOverlappingWith($candidate, [
                new DeclaredService(1, '2000-01-01', '2004-12-31'),
                new DeclaredService(2, '2005-01-01', '2019-12-31'),
            ]),
        );
    }

    public function test_a_candidate_sharing_one_day_overlaps(): void
    {
        // Inclusive bound: the candidate's start IS the stored end.
        $candidate = new DeclaredService(0, '2005-12-31', '2008-12-31');

        self::assertSame(
            [7],
            ServicePeriods::idsOverlappingWith($candidate, [
                new DeclaredService(7, '2000-01-01', '2005-12-31'),
            ]),
        );
    }

    public function test_a_candidate_starting_the_day_after_the_stored_end_does_not_overlap(): void
    {
        $candidate = new DeclaredService(0, '2006-01-01', '2008-12-31');

        self::assertSame(
            [],
            ServicePeriods::idsOverlappingWith($candidate, [
                new DeclaredService(7, '2000-01-01', '2005-12-31'),
            ]),
        );
    }

    /** @return array<string, array{DeclaredService, bool}> */
    public static function appendixMarkers(): array
    {
        return [
            'coletilla flagged' => [new DeclaredService(1, '2000-01-01', '2005-12-31', true), true],
            'ordinary service' => [new DeclaredService(1, '2000-01-01', '2005-12-31', false), false],
            'defaults to ordinary' => [new DeclaredService(1, '2000-01-01', '2005-12-31'), false],
        ];
    }

    #[DataProvider('appendixMarkers')]
    public function test_carries_the_coletilla_marker(DeclaredService $service, bool $expected): void
    {
        self::assertSame($expected, $service->isAppendix);
    }
}
