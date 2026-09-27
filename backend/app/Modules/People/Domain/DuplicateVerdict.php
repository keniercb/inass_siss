<?php

declare(strict_types=1);

namespace App\Modules\People\Domain;

/**
 * The outcome of the duplicate policy (RF-PER-005).
 */
enum DuplicateVerdict
{
    /**
     * Nothing collides: the registration may proceed.
     */
    case Allow;

    /**
     * The identity number is already registered (RN-001): hard block,
     * the registered person must be returned to the caller. Not
     * confirmable — an identity belongs to exactly one person.
     */
    case IdentityRegistered;

    /**
     * Homonym candidates exist (same first name, first surname and
     * birth date): the operator must confirm the registration.
     */
    case HomonymWarning;
}
