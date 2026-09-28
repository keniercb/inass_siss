<?php

declare(strict_types=1);

namespace App\Modules\Security\Domain\Authentication;

use App\Modules\Shared\Contracts\ClockInterface;
use DateTimeImmutable;

/**
 * Brute-force lockout policy (RF-SEG-001 "bloqueo temporal tras N
 * intentos fallidos", ADR-24).
 *
 * The locked state is DERIVED at read time: an account is locked
 * while now < locked_at + TTL, so the auto-unlock happens by the
 * calendar and no unlocked_at column can drift out of sync. The
 * Administrator can always unlock earlier (POST /users/{id}/unlock).
 * Failed attempts are consecutive: a stale lock that already expired
 * restarts the counter instead of accumulating across cycles. All
 * time resolution goes through the Shared clock port.
 */
final readonly class LockoutPolicy
{
    public function __construct(
        public readonly int $maxAttempts,
        public readonly int $ttlSeconds,
    ) {}

    /**
     * Whether the account is locked at the current instant. A null
     * locked_at (never locked, or unlocked by the Administrator)
     * answers false; the TTL boundary itself is unlocked (inclusive
     * expiry).
     */
    public function isLocked(?DateTimeImmutable $lockedAt, ClockInterface $clock): bool
    {
        if ($lockedAt === null) {
            return false;
        }

        $unlocksAt = $lockedAt
            ->setTimezone(new \DateTimeZone('UTC'))
            ->modify(sprintf('+%d seconds', $this->ttlSeconds));

        return $clock->now() < $unlocksAt;
    }

    /**
     * Transition for a failed login attempt: decides the next
     * bookkeeping state from the current one. Locks exactly when the
     * consecutive attempts reach the maximum (the lock instant is
     * the failure instant), preserves the ORIGINAL instant of a lock
     * still in force (failures never extend a running lock — the
     * login flow rejects locked accounts before counting, so the
     * branch is defensive) and restarts the counter after a stale
     * (expired) lock so cycles do not accumulate forever.
     */
    public function registerFailure(int $failedAttempts, ?DateTimeImmutable $lockedAt, ClockInterface $clock): LockoutState
    {
        $staleLock = $lockedAt !== null && ! $this->isLocked($lockedAt, $clock);

        if ($staleLock) {
            $failedAttempts = 0;
            $lockedAt = null;
        }

        $attempts = $failedAttempts + 1;

        return new LockoutState(
            failedAttempts: $attempts,
            lockedAt: $attempts >= $this->maxAttempts
                ? ($lockedAt ?? $clock->now())
                : $lockedAt,
        );
    }
}
