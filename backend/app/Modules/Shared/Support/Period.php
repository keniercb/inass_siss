<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Date range value object (RN-006).
 *
 * Every ranged concept in the domain (salary periods, service records, legal
 * validity windows) reuses this invariant: the end, when present, is always
 * greater than or equal to the start. Open-ended ranges (e.g. a service
 * record still active) are supported and treated as "up to now" by
 * `contains()` and `overlaps()` callers.
 */
final class Period
{
    private function __construct(
        public readonly DateTimeImmutable $start,
        public readonly ?DateTimeImmutable $end,
    ) {
        if ($end !== null && $end < $start) {
            throw new InvalidArgumentException(
                sprintf(
                    'Period end (%s) cannot be earlier than its start (%s).',
                    $end->format('Y-m-d'),
                    $start->format('Y-m-d')
                )
            );
        }
    }

    public static function between(DateTimeImmutable $start, DateTimeImmutable $end): self
    {
        return new self($start, $end);
    }

    /** Creates an open-ended period (still in course). */
    public static function starting(DateTimeImmutable $start): self
    {
        return new self($start, null);
    }

    public function hasEnd(): bool
    {
        return $this->end !== null;
    }

    /**
     * Whole days between start and end (end exclusive), following the
     * convention used by the service-year computation (RF-CAL-002).
     *
     * @return int<0, max>
     */
    public function lengthInDays(): int
    {
        if ($this->end === null) {
            throw new \LogicException('Cannot measure the length of an open-ended period.');
        }

        $days = (int) $this->end->diff($this->start)->days;

        return max(0, $days);
    }

    public function contains(DateTimeImmutable $date): bool
    {
        if ($date < $this->start) {
            return false;
        }

        return $this->end === null || $date <= $this->end;
    }

    /**
     * Whether the two periods share at least one instant. Open-ended
     * periods overlap everything that reaches beyond their start; a
     * closed period never overlaps an open period that starts later.
     */
    public function overlaps(self $other): bool
    {
        $latestStart = max($this->start, $other->start);

        if ($this->end === null && $other->end === null) {
            return true;
        }

        $earliestEnd = match (true) {
            $this->end === null => $other->end,
            $other->end === null => $this->end,
            default => min($this->end, $other->end),
        };

        return $latestStart <= $earliestEnd;
    }

    public function equals(self $other): bool
    {
        return $this->start == $other->start && $this->end == $other->end;
    }
}
