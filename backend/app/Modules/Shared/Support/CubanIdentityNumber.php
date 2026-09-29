<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use InvalidArgumentException;
use Stringable;

/**
 * Cuban identity card number value object (RN-001).
 *
 * The 11-digit number is structured as:
 *
 *  [1..2]   birth year YY (NOT validated: the century is not
 *           encoded in the number, so these digits are free)
 *  [3..4]   birth month MM (01-12)
 *  [5..6]   birth day DD (01-31, flat range)
 *  [7..11]  registry sequence, unverified
 *
 * The sex is encoded by digit 10: even means male, odd means
 * female — gender() resolves it. The registry check digit (P-08,
 * open question) stays unverified, as does the year block: those
 * validations were deliberately dropped (the century cannot be
 * resolved from a 2-digit year and no official algorithm for the
 * check digit is public), so the structural policy above is the
 * whole contract.
 */
final class CubanIdentityNumber implements \JsonSerializable, Stringable
{
    /** @var non-empty-string 11 digits, validated */
    private readonly string $number;

    private function __construct(string $number)
    {
        if (preg_match('/^\d{11}$/', $number) !== 1) {
            throw new InvalidArgumentException(
                sprintf('"%s" is not a valid identity number: exactly 11 digits are required.', $number)
            );
        }

        $month = (int) substr($number, 2, 2);
        $day = (int) substr($number, 4, 2);

        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException(
                sprintf('Identity number "%s" encodes an invalid month (%02d) in digits 3-4.', $number, $month)
            );
        }

        if ($day < 1 || $day > 31) {
            throw new InvalidArgumentException(
                sprintf('Identity number "%s" encodes an invalid day (%02d) in digits 5-6.', $number, $day)
            );
        }

        $this->number = $number;
    }

    public static function fromString(string $number): self
    {
        return new self(trim($number));
    }

    /** @return non-empty-string the 11 digits */
    public function number(): string
    {
        return $this->number;
    }

    /**
     * The sex encoded by digit 10: even means male, odd means
     * female.
     *
     * @return 'M'|'F'
     */
    public function gender(): string
    {
        return ((int) $this->number[9]) % 2 === 0 ? 'M' : 'F';
    }

    public function equals(self $other): bool
    {
        return $this->number === $other->number;
    }

    /** @return non-empty-string */
    public function __toString(): string
    {
        return $this->number;
    }

    public function jsonSerialize(): string
    {
        return $this->number;
    }
}
