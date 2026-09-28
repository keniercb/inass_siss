<?php

declare(strict_types=1);

use App\Modules\Security\Domain\Authentication\PasswordPolicy;
use App\Modules\Shared\Contracts\ClockInterface;

// Password policy domain rules (RF-SEG-001 "política de contraseñas",
// ADR-24): minimum length, complexity (upper + lower + digit) and the
// OPTIONAL expiry counted from password_changed_at. Pure value: no
// config, no I/O — the Application layer builds it from configuration
// and tests freeze the clock through the Shared port.

describe('PasswordPolicy', function (): void {
    describe('violations', function (): void {
        it('accepts a conforming password', function (): void {
            $policy = new PasswordPolicy(10, true, null);

            expect($policy->violations('Segura2026'))->toBe([]);
        });

        it('rejects a password shorter than the minimum length', function (): void {
            $policy = new PasswordPolicy(10, true, null);

            expect($policy->violations('Seg1'))->toBe(['min_length']);
        });

        it('reports the missing character classes individually', function (): void {
            $policy = new PasswordPolicy(10, true, null);

            // Only lowercase letters: misses upper and digit, length ok.
            expect($policy->violations('solominusculas'))
                ->toBe(['missing_uppercase', 'missing_digit']);

            // Only uppercase letters: misses lower and digit.
            expect($policy->violations('SOLOMAYUSCULAS'))
                ->toBe(['missing_lowercase', 'missing_digit']);

            // Letters only, mixed case: misses the digit (and length).
            expect($policy->violations('MixtoCase'))->toBe(['min_length', 'missing_digit']);

            // Digits and upper only, one character short: length and
            // lowercase both fail.
            expect($policy->violations('12345678A'))->toBe(['min_length', 'missing_lowercase']);
        });

        it('skips complexity when disabled', function (): void {
            $policy = new PasswordPolicy(6, false, null);

            expect($policy->violations('abc123'))->toBe([]);
        });

        it('treats an empty password as violating everything it can', function (): void {
            $policy = new PasswordPolicy(10, true, null);

            expect($policy->violations(''))
                ->toBe(['min_length', 'missing_uppercase', 'missing_lowercase', 'missing_digit']);
        });

        it('handles multibyte passwords without crashing', function (): void {
            $policy = new PasswordPolicy(6, true, null);

            // Cyrillic lowercase + digit: length ok, but no ASCII upper.
            $violations = $policy->violations('пароль1');

            expect($violations)->toBe(['missing_uppercase']);
        });
    });

    describe('requiresRenewal', function (): void {
        it('never requires renewal when the expiry is disabled', function (): void {
            $policy = new PasswordPolicy(10, true, null);
            $old = securityFixedClock('2020-01-01');

            expect($policy->requiresRenewal(
                new DateTimeImmutable('2010-01-01'),
                $old,
            ))->toBeFalse();
        });

        it('never requires renewal when the baseline is unknown', function (): void {
            $policy = new PasswordPolicy(10, true, 90);

            expect($policy->requiresRenewal(null, securityFixedClock('2026-09-28')))->toBeFalse();
        });

        it('requires renewal once the max age is reached', function (): void {
            $policy = new PasswordPolicy(10, true, 90);
            $clock = securityFixedClock('2026-09-28');

            // Changed 91 days ago: already expired.
            expect($policy->requiresRenewal(
                new DateTimeImmutable('2026-06-29'),
                $clock,
            ))->toBeTrue();

            // Changed 89 days ago: still valid.
            expect($policy->requiresRenewal(
                new DateTimeImmutable('2026-07-01'),
                $clock,
            ))->toBeFalse();
        });

        it('expires exactly at the boundary day (inclusive)', function (): void {
            $policy = new PasswordPolicy(10, true, 90);

            // Changed exactly 90 days ago: today is the renewal day.
            expect($policy->requiresRenewal(
                new DateTimeImmutable('2026-06-30'),
                securityFixedClock('2026-09-28'),
            ))->toBeTrue();
        });
    });
});

/**
 * Fixed clock helper (same pattern as legalFixedClock/
 * signatureClock): domain rules resolve against a frozen date so the
 * boundary semantics are deterministic. Module-prefixed name because
 * Pest helper files share the process (registered lesson, Task 19).
 */
function securityFixedClock(string $today): ClockInterface
{
    return new class($today) implements ClockInterface
    {
        public function __construct(private readonly string $today) {}

        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable($this->today, new DateTimeZone('UTC'));
        }
    };
}
