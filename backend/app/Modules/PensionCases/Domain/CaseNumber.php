<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Domain;

/**
 * Case number value object (user rule 2, ADR-32): eleven digits in
 * three sections separated by a hyphen (minus sign) —
 *
 *      PP-YYYY-CCCCC
 *      │    │    └── annual consecutive, zero padded to five
 *      │    └─────── current year, four digits
 *      └──────────── province code of the registering office, two digits
 *
 * The value object owns the SHAPE only: where each section comes
 * from (province of the registering office, the domain clock, the
 * shared annual sequence) is the service's decision, so formatting
 * and validation can never drift apart between call sites. Pure and
 * immutable like its siblings (Money, Period): no I/O, no clock, no
 * sequence — construct, validate, read.
 */
final class CaseNumber
{
    /** Ceiling of the consecutive: five digits once padded. */
    public const int MAX_CONSECUTIVE = 99999;

    private function __construct(public readonly string $value)
    {
        // Constructed exclusively through fromParts: every instance
        // is born validated.
    }

    /**
     * The SHAPE rules live INSIDE this constructor gate, so the
     * docblock stays unannotated by design: callers pass plain
     * ints/strings and the value object itself rejects anything
     * outside two digits of province code, four digits of year and
     * a consecutive between 1 and 99999.
     *
     * @param  string  $provinceCode  province of the registering office (ONEI catalog: 01-15, 99)
     * @param  int  $year  current year, four digits
     * @param  int  $consecutive  annual consecutive starting at 1
     */
    public static function fromParts(string $provinceCode, int $year, int $consecutive): self
    {
        if (preg_match('/^\d{2}$/', $provinceCode) !== 1) {
            throw new \InvalidArgumentException(
                "The case number province code must be exactly two digits, '{$provinceCode}' given.",
            );
        }

        if ($year < 1000 || $year > 9999) {
            throw new \InvalidArgumentException(
                "The case number year must be four digits, {$year} given.",
            );
        }

        if ($consecutive < 1 || $consecutive > self::MAX_CONSECUTIVE) {
            throw new \InvalidArgumentException(
                'The case number consecutive must be between 1 and '.self::MAX_CONSECUTIVE.", {$consecutive} given.",
            );
        }

        return new self(sprintf('%s-%04d-%05d', $provinceCode, $year, $consecutive));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
