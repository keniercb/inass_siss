<?php

declare(strict_types=1);

// Signature validity as pure Domain (RF-ENT-003): the status of an
// authorized signature is always derived from its optional validity
// window evaluated at "now" (Shared Clock port), never stored. This
// mirrors the derived deceased flag of People and keeps the
// revocation/versioning semantics in one place.

use App\Modules\Organizations\Domain\SignatureStatus;
use App\Modules\Shared\Contracts\ClockInterface;

describe('SignatureStatus', function () {
    it('resolves active when the window covers today', function () {
        expect(SignatureStatus::resolve('2020-01-01', '2099-12-31', signatureClock('2026-09-28')))->toBe(SignatureStatus::Active);
    });

    it('resolves active when both dates are missing (indefinite)', function () {
        expect(SignatureStatus::resolve(null, null, signatureClock('2026-09-28')))->toBe(SignatureStatus::Active);
    });

    it('resolves active when only the start is present and already passed', function () {
        expect(SignatureStatus::resolve('2020-01-01', null, signatureClock('2026-09-28')))->toBe(SignatureStatus::Active);
    });

    it('resolves active when only the end is present and still ahead', function () {
        expect(SignatureStatus::resolve(null, '2099-12-31', signatureClock('2026-09-28')))->toBe(SignatureStatus::Active);
    });

    it('resolves future when the window has not started', function () {
        expect(SignatureStatus::resolve('2027-01-01', '2099-12-31', signatureClock('2026-09-28')))->toBe(SignatureStatus::Future);
    });

    it('resolves expired when the window already closed', function () {
        expect(SignatureStatus::resolve('2020-01-01', '2026-09-27', signatureClock('2026-09-28')))->toBe(SignatureStatus::Expired);
    });

    it('treats the boundary days as inclusive', function () {
        expect(SignatureStatus::resolve('2026-09-28', '2026-09-28', signatureClock('2026-09-28')))->toBe(SignatureStatus::Active);
    });

    it('resolves expired for an end that is today minus one', function () {
        expect(SignatureStatus::resolve(null, '2026-09-27', signatureClock('2026-09-28')))->toBe(SignatureStatus::Expired);
    });

    it('is future for a start that is today plus one', function () {
        expect(SignatureStatus::resolve('2026-09-29', null, signatureClock('2026-09-28')))->toBe(SignatureStatus::Future);
    });
});

/**
 * Fixed clock helper: the domain resolves against a date, so the
 * datasets stay deterministic no matter when the suite runs.
 */
function signatureClock(string $today): ClockInterface
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
