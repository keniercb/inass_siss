<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use DateTimeImmutable;
use InvalidArgumentException;
use Stringable;

/**
 * Cuban identity card number value object (RN-001).
 *
 * The 11-digit number is structured as:
 *
 *  [1]      century & gender prefix:
 *           1 = male born 1900-1999, 2 = female born 1900-1999,
 *           3 = male born 2000-2099, 4 = female born 2000-2099,
 *           5 = male born 1800-1899, 6 = female born 1800-1899
 *  [2..7]   birth date YYMMDD (validated against the real calendar,
 *           including leap years)
 *  [8..10]  issuance sequence number
 *  [11]     registry check digit
 *
 * The constructor enforces the full structural validation. The check digit
 * (position 11) is NOT structurally verified yet: no official public
 * algorithm is verifiable for it, so enforcement is deferred to a checksum
 * policy once the Ministry confirms the rule (open question P-08, tracked in
 * Requisitos funcionales.md section 7).
 */
final class CubanIdentityNumber implements \JsonSerializable, Stringable
{
    /** @var array<int, int> leading digit => base century (e.g. 1 => 1900) */
    private const array CENTURY_BY_PREFIX = [
        1 => 1900, 2 => 1900,
        3 => 2000, 4 => 2000,
        5 => 1800, 6 => 1800,
    ];

    /** @var array<int, non-empty-string> leading digit => gender (RN-002) */
    private const array GENDER_BY_PREFIX = [
        1 => 'M', 2 => 'F',
        3 => 'M', 4 => 'F',
        5 => 'M', 6 => 'F',
    ];

    /** @var non-empty-string 11 digits, validated */
    private readonly string $number;

    private function __construct(string $number)
    {
        if (preg_match('/^\d{11}$/', $number) !== 1) {
            throw new InvalidArgumentException(
                sprintf('"%s" is not a valid identity number: exactly 11 digits are required.', $number)
            );
        }

        $prefix = (int) $number[0];
        if (! isset(self::CENTURY_BY_PREFIX[$prefix])) {
            throw new InvalidArgumentException(
                sprintf('Identity number prefix "%d" is not a valid century/gender code (expected 1-6).', $prefix)
            );
        }

        $century = self::CENTURY_BY_PREFIX[$prefix];
        $year = $century + (int) substr($number, 1, 2);
        $month = (int) substr($number, 3, 2);
        $day = (int) substr($number, 5, 2);

        if (! checkdate($month, $day, $year)) {
            throw new InvalidArgumentException(
                sprintf('Identity number "%s" encodes an invalid birth date (%04d-%02d-%02d).', $number, $year, $month, $day)
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

    /** Birth date resolved to its real century. */
    public function birthDate(): DateTimeImmutable
    {
        $prefix = (int) $this->number[0];
        $year = self::CENTURY_BY_PREFIX[$prefix] + (int) substr($this->number, 1, 2);

        return new DateTimeImmutable(
            sprintf('%04d-%s-%s', $year, substr($this->number, 3, 2), substr($this->number, 5, 2))
        );
    }

    /** @return 'M'|'F' */
    public function gender(): string
    {
        return self::GENDER_BY_PREFIX[(int) $this->number[0]];
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
