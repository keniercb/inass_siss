<?php

declare(strict_types=1);

// Legal basis validity as pure Domain (RF-LEG-003, RN-006): whether a
// norm is in force is derived from its dates evaluated at "now"
// (Shared Clock port) — never a stored column. A norm is derogated
// from its derogation_date (inclusive: it stops being in force that
// day) and in force from its effective_date (inclusive).

use App\Modules\LegalBasis\Domain\LegalBasisStatus;
use App\Modules\Shared\Contracts\ClockInterface;

describe('LegalBasisStatus', function () {
    it('resolves future when the effective date has not arrived', function () {
        expect(LegalBasisStatus::resolve('2999-01-01', null, legalFixedClock('2026-09-28')))->toBe(LegalBasisStatus::Future);
    });

    it('resolves effective when in force without derogation', function () {
        expect(LegalBasisStatus::resolve('2020-01-01', null, legalFixedClock('2026-09-28')))->toBe(LegalBasisStatus::Effective);
    });

    it('resolves effective when derogation is still ahead', function () {
        expect(LegalBasisStatus::resolve('2020-01-01', '2999-12-31', legalFixedClock('2026-09-28')))->toBe(LegalBasisStatus::Effective);
    });

    it('resolves derogated when the derogation date has passed', function () {
        expect(LegalBasisStatus::resolve('2020-01-01', '2026-09-27', legalFixedClock('2026-09-28')))->toBe(LegalBasisStatus::Derogated);
    });

    it('treats the derogation day itself as derogated (inclusive cut)', function () {
        expect(LegalBasisStatus::resolve('2020-01-01', '2026-09-28', legalFixedClock('2026-09-28')))->toBe(LegalBasisStatus::Derogated);
    });

    it('treats the effective day itself as in force (inclusive start)', function () {
        expect(LegalBasisStatus::resolve('2026-09-28', null, legalFixedClock('2026-09-28')))->toBe(LegalBasisStatus::Effective);
    });

    it('resolves derogated even for a norm that never became derogation-free', function () {
        // Effective 2020, derogated 2021: history, not in force.
        expect(LegalBasisStatus::resolve('2020-01-01', '2021-01-01', legalFixedClock('2026-09-28')))->toBe(LegalBasisStatus::Derogated);
    });

    it('resolves future even when a derogation is already scheduled', function () {
        // Norm that will enter into force in 2999 and die in 2999+.
        expect(LegalBasisStatus::resolve('2999-06-01', '2999-12-31', legalFixedClock('2026-09-28')))->toBe(LegalBasisStatus::Future);
    });
});

/**
 * Fixed clock helper: the domain resolves against a date, so the
 * datasets stay deterministic no matter when the suite runs.
 */
function legalFixedClock(string $today): ClockInterface
{
    return new class($today) implements ClockInterface
    {
        public function __construct(private readonly string $today) {}

        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable($this->today);
        }
    };
}
