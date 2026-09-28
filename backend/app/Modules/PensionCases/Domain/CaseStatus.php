<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Domain;

/**
 * Normative status catalog of a pension case (requirements section
 * 2.4). The transition MATRIX — which status may move to which — is
 * deliberately NOT here: S6 codifies it first as a Pest dataset and
 * then as the state machine (plan 7.4), so this enum only fixes the
 * four legal values plus the two properties Sprint 5 consumes.
 *
 * `isEditable` answers the subrecord rule (plan S5.4): salary,
 * service and cycle rows are only writable while the case sits in
 * its pre-review state (`submitted`) — once the specialist pulls it
 * into review, the evidence is frozen and corrections travel back
 * through the devolución transition.
 */
enum CaseStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /** Whether subrecords may still be added or removed (plan S5.4). */
    public function isEditable(): bool
    {
        return $this === self::Submitted;
    }

    /**
     * Resolution states (section 2.4): terminal except for the
     * administrative reopening exception (RF-EXP-010, S6).
     */
    public function isTerminal(): bool
    {
        return $this === self::Approved || $this === self::Rejected;
    }
}
