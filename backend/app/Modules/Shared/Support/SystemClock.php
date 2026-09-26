<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use App\Modules\Shared\Contracts\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Production clock: resolves the current UTC instant.
 *
 * Bound to ClockInterface in SharedServiceProvider. Tests freeze time with
 * their own fake implementation instead of touching this class.
 */
final class SystemClock implements ClockInterface
{
    private readonly DateTimeZone $timezone;

    public function __construct()
    {
        $this->timezone = new DateTimeZone('UTC');
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->timezone);
    }
}
