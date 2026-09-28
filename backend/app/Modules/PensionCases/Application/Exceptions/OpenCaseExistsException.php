<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Application\Exceptions;

use App\Modules\PensionCases\Infrastructure\Persistence\Models\PensionCase;
use RuntimeException;

/**
 * Raised when a new case is created for a person that already holds
 * an open (non-terminal) one (plan S5.1: "constraint único de
 * expediente abierto por persona").
 *
 * The controller answers HTTP 409 carrying the open case so the
 * operator continues the existing trámite instead of forking a
 * second one; the open_case_key UNIQUE index is the physical
 * backstop of this same rule.
 */
final class OpenCaseExistsException extends RuntimeException
{
    public function __construct(public readonly PensionCase $openCase)
    {
        parent::__construct(sprintf(
            'Person %d already holds open case %s.',
            $openCase->applicant_person_id,
            $openCase->number,
        ));
    }
}
