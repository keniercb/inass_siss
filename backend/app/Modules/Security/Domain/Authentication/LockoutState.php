<?php

declare(strict_types=1);

namespace App\Modules\Security\Domain\Authentication;

use DateTimeImmutable;

/**
 * Bookkeeping outcome of a failed-login transition (ADR-24): the
 * consecutive attempt counter and the lock instant to persist. A
 * plain readonly pair instead of an array so the repository port
 * stays typed and the semantics stay in the domain.
 */
final readonly class LockoutState
{
    public function __construct(
        public readonly int $failedAttempts,
        public readonly ?DateTimeImmutable $lockedAt,
    ) {}
}
