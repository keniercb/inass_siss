<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use InvalidArgumentException;
use Stringable;

/**
 * Immutable monetary value (RNF-008, RN-005).
 *
 * Money is stored as integer minor units (cents) and is ALWAYS created from
 * strings, never from floats: every public factory rejects anything that is
 * not an exact decimal string. Arithmetic uses bcmath (arbitrary precision)
 * so multiplication can never silently overflow, and rounding is performed
 * with the banker's rule (half to even, ROUND_HALF_EVEN) as required by the
 * calculation engine (RF-CAL-003).
 *
 * Amounts are non-negative: pension amounts, salaries and percentages are
 * naturally >= 0, and subtract() refuses to go below zero to surface domain
 * errors (e.g. an over-refund) at the lowest possible layer.
 */
final class Money implements \JsonSerializable, Stringable
{
    /** DECIMAL(12,2): at most 10 integer digits + 2 decimals (ADR-06). */
    private const int MAX_MINOR_UNITS = 999_999_999_999;

    private const string FORMAT_PATTERN = '/^\d{1,10}(\.\d{1,2})?$/';

    private const string FACTOR_PATTERN = '/^\d{1,4}(\.\d{1,4})?$/';

    /** @var int<0, max> */
    private readonly int $minorUnits;

    private function __construct(int $minorUnits)
    {
        if ($minorUnits < 0) {
            throw new InvalidArgumentException('Money cannot be negative.');
        }
        if ($minorUnits > self::MAX_MINOR_UNITS) {
            throw new InvalidArgumentException(
                sprintf('Money amount %d exceeds the DECIMAL(12,2) limit.', $minorUnits)
            );
        }
        $this->minorUnits = $minorUnits;
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Builds a Money from an exact decimal string such as "5000.00".
     *
     * Accepts up to two decimals; "1234" and "1234.5" are normalized to
     * "1234.00" and "1234.50". Anything else (signs, thousands separators,
     * three decimals, scientific notation, float-typed input) is rejected.
     */
    public static function fromString(string $amount): self
    {
        $amount = trim($amount);
        if (preg_match(self::FORMAT_PATTERN, $amount) !== 1) {
            throw new InvalidArgumentException(
                sprintf('"%s" is not a valid money amount (expected "1234.56").', $amount)
            );
        }

        [$integers, $decimals] = array_pad(explode('.', $amount, 2), 2, '0');

        $minorUnits = ((int) $integers * 100) + (int) str_pad($decimals, 2, '0');

        return new self($minorUnits);
    }

    /** @param int<0, max> $minorUnits */
    public static function fromMinorUnits(int $minorUnits): self
    {
        return new self($minorUnits);
    }

    public function add(self $other): self
    {
        return new self($this->minorUnits + $other->minorUnits);
    }

    /**
     * @throws \DomainException when the result would be negative
     */
    public function subtract(self $other): self
    {
        $result = $this->minorUnits - $other->minorUnits;
        if ($result < 0) {
            throw new \DomainException('Money subtraction would produce a negative amount.');
        }

        return new self($result);
    }

    /**
     * Multiplies by an exact non-negative decimal factor, e.g. "0.65" for 65 %.
     *
     * The intermediate product is computed with bcmath (no floats involved,
     * no overflow risk) and the result is rounded to cents using the
     * banker's rule (half to even).
     */
    public function multiply(string $factor): self
    {
        $factor = trim($factor);
        if (preg_match(self::FACTOR_PATTERN, $factor) !== 1) {
            throw new InvalidArgumentException(
                sprintf('"%s" is not a valid multiplication factor (expected e.g. "0.65").', $factor)
            );
        }

        [$integers, $decimals] = array_pad(explode('.', $factor, 2), 2, '0');
        $factorScaled = (int) ($integers.$decimals);
        $scale = strlen($decimals);

        $product = bcmul((string) $this->minorUnits, (string) $factorScaled);
        $divisor = bcpow('10', (string) $scale);

        $rounded = $scale === 0
            ? $product
            : self::roundHalfEven($product, $divisor);

        return new self((int) $rounded);
    }

    /**
     * Applies a percentage expressed as an exact decimal string ("60" = 60 %).
     */
    public function percentage(string $percent): self
    {
        return $this->multiply(self::scaleDown($percent, 2));
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits;
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->minorUnits > $other->minorUnits;
    }

    public function isLessThan(self $other): bool
    {
        return $this->minorUnits < $other->minorUnits;
    }

    /** @return int<0, max> */
    public function toMinorUnits(): int
    {
        return $this->minorUnits;
    }

    /**
     * Canonical string representation, always with two decimals.
     *
     * @return non-empty-string
     */
    public function __toString(): string
    {
        return sprintf('%d.%02d', intdiv($this->minorUnits, 100), $this->minorUnits % 100);
    }

    /** Serializes as a string so JSON payloads never carry floats (RNF-008). */
    public function jsonSerialize(): string
    {
        return (string) $this;
    }

    /**
     * Divides $numerator by $divisor using banker's rounding (half to even).
     *
     * @param  numeric-string  $numerator  non-negative integer as string
     * @param  numeric-string  $divisor  power of ten, > 1
     */
    private static function roundHalfEven(string $numerator, string $divisor): string
    {
        $quotient = bcdiv($numerator, $divisor, 0);
        $remainder = bcmod($numerator, $divisor);

        $twiceRemainder = bcmul($remainder, '2');
        $comparison = bccomp($twiceRemainder, $divisor);

        $roundUp = $comparison > 0
            || ($comparison === 0 && bcmod($quotient, '2') !== '0');

        return $roundUp ? bcadd($quotient, '1') : $quotient;
    }

    /** Divides a decimal string by 10^$shift, extending precision as needed. */
    private static function scaleDown(string $value, int $shift): string
    {
        [$integers, $decimals] = array_pad(explode('.', $value, 2), 2, '');

        $digits = $integers.$decimals;
        $point = strlen($digits) - strlen($decimals) - $shift;

        if ($point < 0) {
            $digits = str_pad($digits, strlen($digits) - $point, '0', STR_PAD_LEFT);
            $point = 0;
        }

        $scaled = rtrim(
            sprintf('%s.%s', substr($digits, 0, $point), substr($digits, $point)),
            '.'
        );

        return str_starts_with($scaled, '.') ? '0'.$scaled : $scaled;
    }
}
