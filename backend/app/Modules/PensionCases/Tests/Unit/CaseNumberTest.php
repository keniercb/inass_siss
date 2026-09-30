<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Tests\Unit;

use App\Modules\PensionCases\Domain\CaseNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Case number composition (user rule 2 / ADR-32): eleven digits in
 * three sections separated by a hyphen — two digits of the province
 * code, four of the current year and the annual consecutive padded
 * with leading zeros up to five positions. The value object owns the
 * shape so the service only decides *where* each section comes from
 * (province of the registering office, clock year, shared annual
 * sequence), never how to format them.
 */
final class CaseNumberTest extends TestCase
{
    public function test_composes_the_three_sections_separated_by_hyphens(): void
    {
        $number = CaseNumber::fromParts('03', 2026, 1);

        $this->assertSame('03-2026-00001', $number->__toString());
    }

    public function test_pads_the_consecutive_with_leading_zeros_up_to_five_digits(): void
    {
        $this->assertSame('08-2026-00042', CaseNumber::fromParts('08', 2026, 42)->__toString());
        $this->assertSame('08-2026-01000', CaseNumber::fromParts('08', 2026, 1000)->__toString());
        $this->assertSame('15-2026-99999', CaseNumber::fromParts('15', 2026, 99999)->__toString());
    }

    public function test_the_number_carries_exactly_eleven_digits(): void
    {
        $number = CaseNumber::fromParts('03', 2026, 7);

        $this->assertSame(11, strlen(str_replace('-', '', $number->__toString())));
    }

    #[DataProvider('invalidProvinceCodes')]
    public function test_rejects_province_codes_outside_two_digits(string $provinceCode): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CaseNumber::fromParts($provinceCode, 2026, 1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidProvinceCodes(): array
    {
        return [
            'one digit' => ['3'],
            'three digits' => ['123'],
            'not numeric' => ['AB'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidYears')]
    public function test_rejects_years_outside_four_digits(int $year): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CaseNumber::fromParts('03', $year, 1);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidYears(): array
    {
        return [
            'three digits' => [999],
            'five digits' => [10000],
        ];
    }

    #[DataProvider('invalidConsecutives')]
    public function test_rejects_consecutives_outside_the_five_digit_range(int $consecutive): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CaseNumber::fromParts('03', 2026, $consecutive);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidConsecutives(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'over the five digit ceiling' => [100000],
        ];
    }
}
