<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Domain;

/**
 * Pure analysis of the declared yearly salary series (RF-EXP-002):
 * "the system warns about consecutive missing years inside the
 * declared series". A year is missing only when it lies STRICTLY
 * between the smallest and the largest declared year — before the
 * first and after the last one there is no declared series to have
 * a hole in, so the warning never fires there.
 *
 * The function is total: it accepts unordered input and even
 * duplicates (the (case, year) unique probe blocks those at the
 * boundary) because pure domain analysis must stay decidable on
 * any list it receives.
 */
final class SalarySeries
{
    private const int FIRST_VALID_YEAR = 1950;

    private function __construct() {}

    /**
     * Years missing between the declared extremes, ascending.
     *
     * @param  list<int>  $years  declared years, any order
     * @return list<int>
     */
    public static function missingConsecutiveYears(array $years): array
    {
        $declared = array_values(array_unique($years));

        if (count($declared) < 2) {
            return [];
        }

        sort($declared);

        $missing = [];

        for ($cursor = $declared[0]; $cursor <= $declared[count($declared) - 1]; $cursor++) {
            if (! in_array($cursor, $declared, true)) {
                $missing[] = $cursor;
            }
        }

        return $missing;
    }

    /**
     * How many distinct years the series declares.
     *
     * @param  list<int>  $years
     */
    public static function declaredYearCount(array $years): int
    {
        return count(array_unique($years));
    }

    /** Normative floor of the salary series (RF-EXP-002, data model). */
    public static function firstValidYear(): int
    {
        return self::FIRST_VALID_YEAR;
    }
}
