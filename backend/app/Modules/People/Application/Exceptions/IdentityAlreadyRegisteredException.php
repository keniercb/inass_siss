<?php

declare(strict_types=1);

namespace App\Modules\People\Application\Exceptions;

use App\Modules\People\Infrastructure\Persistence\Models\Person;
use RuntimeException;

/**
 * Raised when a registration reuses an identity number that is
 * already in the registry (RF-PER-005, RN-001).
 *
 * The controller answers HTTP 409 carrying the registered person, so
 * the operator lands on the existing record instead of creating a
 * second one. The block is not confirmable: an identity belongs to
 * exactly one person, active or deactivated.
 */
final class IdentityAlreadyRegisteredException extends RuntimeException
{
    public function __construct(public readonly Person $person)
    {
        parent::__construct(
            "The identity number {$person->identity_number} is already registered (RN-001).",
        );
    }
}
