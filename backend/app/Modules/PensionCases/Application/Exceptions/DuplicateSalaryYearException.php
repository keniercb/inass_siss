<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Application\Exceptions;

use RuntimeException;

/**
 * Raised when a salary row is declared twice for the same year
 * inside one case (RF-EXP-002: the (case, year) pair is UNIQUE).
 *
 * Semantic 422 probe before the insert (RN-008 convention): the
 * database UNIQUE index is the last defense, not the answer.
 */
final class DuplicateSalaryYearException extends RuntimeException
{
    public function __construct(public readonly int $year, public readonly int $caseId)
    {
        parent::__construct("Year {$year} is already declared in case {$caseId} (RF-EXP-002).");
    }
}
