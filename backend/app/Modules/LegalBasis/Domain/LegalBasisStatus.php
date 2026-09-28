<?php

declare(strict_types=1);

namespace App\Modules\LegalBasis\Domain;

use App\Modules\Shared\Contracts\ClockInterface;

/**
 * Derived state of a legal basis (RF-LEG-003): whether a norm is in
 * force is derived from its dates evaluated at "now" (Shared Clock
 * port) — never a stored column, the same convention as the derived
 * flags of People and signatures. Boundaries are inclusive: a norm
 * is in force from its effective_date and stops being in force on
 * its derogation_date (that day counts as derogated).
 */
enum LegalBasisStatus: string
{
    case Future = 'future';
    case Effective = 'effective';
    case Derogated = 'derogated';

    /**
     * Resolve the state of a norm at "now". Derogation wins over
     * everything (a norm can be born already derogated by a later
     * norm); then the effective date decides between future and in
     * force. Day-granular comparison: dates are DATE columns and
     * "now" is normalized to its date.
     */
    public static function resolve(string $effectiveDate, ?string $derogationDate, ClockInterface $clock): self
    {
        $today = $clock->now()->setTime(0, 0);

        if ($derogationDate !== null && new \DateTimeImmutable($derogationDate) <= $today) {
            return self::Derogated;
        }

        if (new \DateTimeImmutable($effectiveDate) > $today) {
            return self::Future;
        }

        return self::Effective;
    }
}
