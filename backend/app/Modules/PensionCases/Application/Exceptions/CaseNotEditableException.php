<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Application\Exceptions;

use App\Modules\PensionCases\Domain\CaseStatus;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\PensionCase;
use RuntimeException;

/**
 * Raised when a subrecord write (or any editing operation) targets a
 * case that already left its pre-review state (plan S5.4: subrecords
 * are only writable while the case sits in `submitted`).
 *
 * The controller answers HTTP 409 with the case id and its current
 * status: the conflict is with the case's lifecycle state, and the
 * operator must bring the case back through the devolución
 * transition (S6) instead of editing frozen evidence.
 */
final class CaseNotEditableException extends RuntimeException
{
    public function __construct(
        public readonly PensionCase $case,
        public readonly CaseStatus $currentStatus,
    ) {
        parent::__construct(sprintf(
            'Case %s is %s: subrecords are only editable while the case is submitted.',
            $case->number,
            $currentStatus->value,
        ));
    }
}
