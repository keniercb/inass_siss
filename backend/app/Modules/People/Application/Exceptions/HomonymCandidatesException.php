<?php

declare(strict_types=1);

namespace App\Modules\People\Application\Exceptions;

use App\Modules\People\Domain\DuplicateCandidate;
use RuntimeException;

/**
 * Raised when a registration matches living homonym candidates (same
 * first name, first surname and birth date) and the request did not
 * carry the operator's confirmation (RF-PER-005, "aviso confirmable").
 *
 * The controller answers HTTP 409 with the candidates, so the
 * operator can either navigate to one of them or resubmit with
 * confirm=true.
 */
final class HomonymCandidatesException extends RuntimeException
{
    /**
     * @param  array<int, DuplicateCandidate>  $candidates
     */
    public function __construct(public readonly array $candidates)
    {
        parent::__construct(
            sprintf(
                '%d living homonym candidate(s) share the first name, first surname and birth date. Confirm the registration to proceed.',
                count($candidates),
            ),
        );
    }
}
