<?php

declare(strict_types=1);

namespace App\Modules\Settings\Domain;

use DateTimeImmutable;

/**
 * Domain action that resolves the effective settings version at a given
 * date (RN-007): the version in force is the one with the greatest
 * effective_from less than or equal to the requested date.
 *
 * Pure by design (ADR-16): no persistence, no framework types, so the
 * vigencia semantics are fixed by unit datasets and stay independent of
 * how the timeline is stored. The boundary rule — a version is in force
 * from its own effective_from date, inclusive — is what makes the
 * UNIQUE constraint on effective_from a complete no-overlap guarantee:
 * distinct effective dates always partition the timeline.
 */
final class EffectiveSettingsResolver
{
    /**
     * @param  list<EffectiveSettingCandidate>  $candidates
     */
    public function resolve(array $candidates, DateTimeImmutable $at): ?EffectiveSettingCandidate
    {
        $effective = null;

        foreach ($candidates as $candidate) {
            if ($candidate->effectiveFrom->format('Y-m-d') <= $at->format('Y-m-d')) {
                if ($effective === null
                    || $candidate->effectiveFrom->format('Y-m-d') > $effective->effectiveFrom->format('Y-m-d')
                ) {
                    $effective = $candidate;
                }
            }
        }

        return $effective;
    }
}
