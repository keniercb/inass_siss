<?php

declare(strict_types=1);

namespace App\Modules\People\Domain;

/**
 * Decides whether a person registration may proceed (RF-PER-005,
 * DA H-08).
 *
 * The policy is a pure domain value: it receives the person already
 * registered with the requested identity number (if any), the living
 * homonym candidates (same first name, first surname and birth date)
 * and the operator's confirmation flag, and answers with a verdict.
 * Priority is deliberate: an identity collision always wins over a
 * homonym warning, because the caller needs the registered person,
 * never a suggestion to confirm. The application layer owns the I/O
 * (the repository lookups) and translates verdicts into HTTP
 * semantics; this class only fixes the business rule.
 */
final class DuplicatePolicy
{
    /**
     * @param  array<int, DuplicateCandidate>  $homonymCandidates  living people matching name/surname/birth date
     */
    public function evaluate(
        ?DuplicateCandidate $registeredWithIdentity,
        array $homonymCandidates,
        bool $confirmed,
    ): DuplicateVerdict {
        if ($registeredWithIdentity !== null) {
            return DuplicateVerdict::IdentityRegistered;
        }

        if ($homonymCandidates !== [] && ! $confirmed) {
            return DuplicateVerdict::HomonymWarning;
        }

        return DuplicateVerdict::Allow;
    }
}
