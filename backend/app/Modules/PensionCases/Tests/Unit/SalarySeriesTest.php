<?php

declare(strict_types=1);

// SalarySeries (RF-EXP-002): la advertencia de años consecutivos
// ausentes es un análisis puro de la serie declarada — los huecos
// ESTRICTAMENTE INTERIORES entre el primer y el último año declarado.
// Antes del primero y después del último no hay «ausencia» posible
// porque la serie solo se conoce donde se declaró.

namespace App\Modules\PensionCases\Tests\Unit;

use App\Modules\PensionCases\Domain\SalarySeries;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SalarySeries::class)]
final class SalarySeriesTest extends TestCase
{
    public function test_a_declared_series_without_interior_gaps_warns_nothing(): void
    {
        self::assertSame([], SalarySeries::missingConsecutiveYears([2018, 2019, 2020, 2021]));
    }

    public function test_a_single_year_never_warns(): void
    {
        // One declared year: no interior to inspect.
        self::assertSame([], SalarySeries::missingConsecutiveYears([1994]));
    }

    public function test_an_empty_series_never_warns(): void
    {
        self::assertSame([], SalarySeries::missingConsecutiveYears([]));
    }

    public function test_reports_every_interior_gap_in_ascending_order(): void
    {
        // 2019-2020 and 2023 missing between 2018 and 2024.
        self::assertSame(
            [2019, 2020, 2023],
            SalarySeries::missingConsecutiveYears([2018, 2021, 2022, 2024]),
        );
    }

    public function test_years_may_arrive_in_any_order(): void
    {
        // The operator registers years as they find the evidence.
        self::assertSame(
            [2001, 2002],
            SalarySeries::missingConsecutiveYears([2003, 2000, 2004]),
        );
    }

    public function test_duplicated_years_do_not_break_the_analysis(): void
    {
        // The unique (case, year) probe blocks duplicates at the API,
        // but the pure function stays total: it just deduplicates.
        self::assertSame(
            [2001],
            SalarySeries::missingConsecutiveYears([2000, 2000, 2002]),
        );
    }

    public function test_years_outside_the_declared_extremes_never_warn(): void
    {
        // 1999 and 2025 sit OUTSIDE the declared bounds: the series
        // only exists between its extremes, so nothing is "missing"
        // before the first or after the last declared year.
        self::assertSame([], SalarySeries::missingConsecutiveYears([2000, 2001]));
        self::assertSame([], SalarySeries::missingConsecutiveYears([2023, 2024]));
    }

    public function test_a_long_interior_hole_warns_about_every_missing_year(): void
    {
        // Declared 2000-2001 then 2023-2024: two decades of interior
        // absence are exactly the evidence gap the RF wants flagged.
        self::assertSame(
            range(2002, 2022),
            SalarySeries::missingConsecutiveYears([2000, 2001, 2023, 2024]),
        );
    }

    /** @return list<array{list<int>, int}> */
    public static function declaredLengths(): array
    {
        return [
            [[], 0],
            [[1994], 1],
            [[1994, 1995, 1996], 3],
        ];
    }

    /**
     * @param  list<int>  $years
     */
    #[DataProvider('declaredLengths')]
    public function test_declared_years_count_duplicates_once(array $years, int $expected): void
    {
        self::assertSame($expected, SalarySeries::declaredYearCount($years));
    }
}
