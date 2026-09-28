<?php

declare(strict_types=1);

// ServicePeriods (RF-EXP-003, H-15): detección de solapamientos y de
// vínculos sin cerrar como análisis puro de los períodos declarados.
// Solapar es compartir al menos un día — dos intervalos se cruzan
// cuando cada uno empieza antes de que el otro termine; un vínculo
// abierto (fin NULL) se extiende hacia el infinito, de modo que
// solapa con todo lo que empieza a partir de su inicio. El resultado
// es ADVERTENCIA, nunca bloqueo: el especialista decide con la
// evidencia delante.

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
                new DeclaredService(3, '2010-01-01', null),
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

    public function test_an_open_service_overlaps_any_later_start(): void
    {
        // Open (end NULL) extends to infinity: 2003 start crosses it.
        self::assertSame(
            [[1, 2]],
            ServicePeriods::overlappingPairs([
                new DeclaredService(1, '2000-01-01', null),
                new DeclaredService(2, '2003-01-01', '2005-12-31'),
            ]),
        );
    }

    public function test_an_open_service_does_not_overlap_a_earlier_closed_period(): void
    {
        // The closed period ends 1999-12-31, before the open start.
        self::assertSame(
            [],
            ServicePeriods::overlappingPairs([
                new DeclaredService(1, '1995-01-01', '1999-12-31'),
                new DeclaredService(2, '2000-01-01', null),
            ]),
        );
    }

    public function test_two_open_services_always_overlap(): void
    {
        self::assertSame(
            [[1, 2]],
            ServicePeriods::overlappingPairs([
                new DeclaredService(1, '1990-01-01', null),
                new DeclaredService(2, '2000-01-01', null),
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
                new DeclaredService(2, '2002-01-01', null),
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

    public function test_collects_open_service_ids_in_declaration_order(): void
    {
        self::assertSame(
            [2, 4],
            ServicePeriods::openServiceIds([
                new DeclaredService(1, '2000-01-01', '2004-12-31'),
                new DeclaredService(2, '2005-01-01', null),
                new DeclaredService(3, '2006-01-01', '2009-12-31'),
                new DeclaredService(4, '2010-01-01', null),
            ]),
        );
    }

    /** @return array<string, array{DeclaredService, bool}> */
    public static function appendixMarkers(): array
    {
        return [
            'coletilla flagged' => [new DeclaredService(1, '2000-01-01', null, true), true],
            'ordinary service' => [new DeclaredService(1, '2000-01-01', null, false), false],
            'defaults to ordinary' => [new DeclaredService(1, '2000-01-01', null), false],
        ];
    }

    #[DataProvider('appendixMarkers')]
    public function test_carries_the_coletilla_marker(DeclaredService $service, bool $expected): void
    {
        self::assertSame($expected, $service->isAppendix);
    }
}
