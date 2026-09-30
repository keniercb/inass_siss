<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Application\Exceptions;

use RuntimeException;

/**
 * Raised when an income concept is declared twice with a value for
 * the same case (user rule 5: the (case, concept) pair is UNIQUE —
 * one declared value per concept).
 *
 * Semantic 422 probe before the insert (RN-008 convention): the
 * database UNIQUE index is the last defense, not the answer.
 */
final class DuplicateIncomeConceptException extends RuntimeException
{
    public function __construct(public readonly int $incomeConceptId, public readonly int $caseId)
    {
        parent::__construct(
            "Income concept {$incomeConceptId} is already declared in case {$caseId} (user rule 5).",
        );
    }
}
