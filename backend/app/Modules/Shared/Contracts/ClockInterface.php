<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

/**
 * Time source abstraction.
 *
 * Production code must never call `now`/`time` directly: every date-sensitive
 * operation (case deadlines, sequence cutoffs, age checks, audit timestamps)
 * resolves time through this contract so tests can freeze it and guarantee
 * deterministic, reproducible runs (see architecture doc, section 12.4).
 */
interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
