<?php

declare(strict_types=1);

namespace App\Modules\People\Domain;

/**
 * A person that the duplicate policy needs to reason about
 * (RF-PER-005): either the person already registered with the
 * requested identity number or one homonym candidate.
 *
 * Pure projection: the application layer maps Eloquent people into
 * these values, so the Domain never touches persistence (ADR-11).
 * Non-emptiness is guaranteed upstream by the request validation
 * (mandatory fields); the domain reasons about complete people.
 */
final class DuplicateCandidate
{
    /**
     * @param  string  $identityNumber  11 digits, validated upstream (RN-001)
     * @param  string  $birthDate  ISO Y-m-d
     */
    public function __construct(
        public readonly int $id,
        public readonly string $identityNumber,
        public readonly string $firstName,
        public readonly string $firstSurname,
        public readonly string $birthDate,
    ) {}
}
