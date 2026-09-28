<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Application\Exceptions;

use App\Modules\People\Infrastructure\Persistence\Models\Person;
use RuntimeException;

/**
 * Raised when a case is created for a person that cannot start a new
 * process (RF-SEG-003: living and active people only, decided by
 * PeopleService::canStartNewProcess).
 *
 * Deceased and deactivated people are different failures: the
 * deceased one is a definitive domain rule, the deactivated one an
 * operator error (the person must be restored first). The reason
 * travels with the exception so the 422 message is actionable.
 */
final class PersonNotEligibleException extends RuntimeException
{
    public static function deceased(Person $person): self
    {
        return new self($person, sprintf(
            'Person %d (%s) is deceased: a deceased person cannot start a new process (RF-SEG-003).',
            $person->id,
            $person->identity_number,
        ));
    }

    public static function deactivated(Person $person): self
    {
        return new self($person, sprintf(
            'Person %d (%s) is deactivated: restore the registry entry before opening a case.',
            $person->id,
            $person->identity_number,
        ));
    }

    private function __construct(
        public readonly Person $person,
        string $message,
    ) {
        parent::__construct($message);
    }
}
