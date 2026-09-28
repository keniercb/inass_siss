<?php

declare(strict_types=1);

use App\Modules\Security\Domain\Authentication\LockoutPolicy;
use App\Modules\Shared\Contracts\ClockInterface;

// Brute-force lockout domain rules (RF-SEG-001 "bloqueo temporal tras
// N intentos fallidos", ADR-24): the locked state derives from
// locked_at + TTL at read time, so there is no unlocked_at column to
// keep synchronized with reality. registerFailure is the single
// transition that decides the next bookkeeping state — pure, clocked
// through the Shared port, never calling now() directly.

describe('LockoutPolicy', function (): void {
    describe('isLocked', function (): void {
        it('is not locked without a lock timestamp', function (): void {
            $policy = new LockoutPolicy(5, 900);

            expect($policy->isLocked(null, lockoutFixedClock('2026-09-28 12:00:00')))->toBeFalse();
        });

        it('is locked while the TTL has not elapsed', function (): void {
            $policy = new LockoutPolicy(5, 900); // 15 minutes

            // Locked at 12:00:00 → unlocks at 12:15:00: still locked
            // one second before the boundary.
            expect($policy->isLocked(
                new DateTimeImmutable('2026-09-28 12:00:00'),
                lockoutFixedClock('2026-09-28 12:14:59'),
            ))->toBeTrue();
        });

        it('auto-unlocks exactly when the TTL elapses (inclusive)', function (): void {
            $policy = new LockoutPolicy(5, 900);

            // Locked at 12:00:00 → unlocked at 12:15:00 sharp.
            expect($policy->isLocked(
                new DateTimeImmutable('2026-09-28 12:00:00'),
                lockoutFixedClock('2026-09-28 12:15:00'),
            ))->toBeFalse();
        });

        it('stays unlocked after the TTL elapsed', function (): void {
            $policy = new LockoutPolicy(5, 900);

            expect($policy->isLocked(
                new DateTimeImmutable('2026-09-28 12:00:00'),
                lockoutFixedClock('2026-09-28 18:00:00'),
            ))->toBeFalse();
        });
    });

    describe('registerFailure', function (): void {
        it('increments the counter without locking below the threshold', function (): void {
            $policy = new LockoutPolicy(5, 900);
            $clock = lockoutFixedClock('2026-09-28 12:00:00');

            $state = $policy->registerFailure(3, null, $clock);

            expect($state->failedAttempts)->toBe(4)
                ->and($state->lockedAt)->toBeNull();
        });

        it('locks exactly when the attempts reach the maximum', function (): void {
            $policy = new LockoutPolicy(5, 900);
            $clock = lockoutFixedClock('2026-09-28 12:00:00');

            $state = $policy->registerFailure(4, null, $clock);

            expect($state->failedAttempts)->toBe(5)
                ->and($state->lockedAt)->toEqual(new DateTimeImmutable('2026-09-28 12:00:00'));
        });

        it('keeps an existing lock in place while still counting', function (): void {
            $policy = new LockoutPolicy(5, 900);
            $clock = lockoutFixedClock('2026-09-28 12:00:00');
            $lockedAt = new DateTimeImmutable('2026-09-28 11:55:00');

            // A failure arriving while locked (e.g. TTL extended window)
            // preserves the original lock instant.
            $state = $policy->registerFailure(7, $lockedAt, $clock);

            expect($state->failedAttempts)->toBe(8)
                ->and($state->lockedAt)->toBe($lockedAt);
        });

        it('restarts the counter when the previous lock already expired', function (): void {
            $policy = new LockoutPolicy(5, 900);
            $clock = lockoutFixedClock('2026-09-28 12:00:00');

            // Locked at 11:00 (TTL ended 11:15): the stale lock clears
            // and the failure counts as the first of a fresh cycle.
            $state = $policy->registerFailure(5, new DateTimeImmutable('2026-09-28 11:00:00'), $clock);

            expect($state->failedAttempts)->toBe(1)
                ->and($state->lockedAt)->toBeNull();
        });

        it('does not lock when the threshold is one', function (): void {
            // Degenerate configuration guard: max_attempts=1 locks on
            // the very first failure (useful for strict deployments).
            $policy = new LockoutPolicy(1, 900);
            $clock = lockoutFixedClock('2026-09-28 12:00:00');

            $state = $policy->registerFailure(0, null, $clock);

            expect($state->failedAttempts)->toBe(1)
                ->and($state->lockedAt)->toEqual(new DateTimeImmutable('2026-09-28 12:00:00'));
        });
    });
});

/**
 * Fixed clock helper (module-prefixed: Pest helper files share the
 * process — registered lesson, Task 19).
 */
function lockoutFixedClock(string $now): ClockInterface
{
    return new class($now) implements ClockInterface
    {
        public function __construct(private readonly string $now) {}

        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable($this->now, new DateTimeZone('UTC'));
        }
    };
}
