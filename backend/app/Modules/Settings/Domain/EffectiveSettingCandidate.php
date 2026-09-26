<?php

declare(strict_types=1);

namespace App\Modules\Settings\Domain;

use DateTimeImmutable;

/**
 * Lightweight timeline entry for the versioned general settings (RN-007).
 *
 * Carries only the identity and the effective date of one settings
 * version: the pure resolver works on this projection so the rule can
 * be fixed and tested without touching persistence. The repository of
 * the Settings module materializes candidates from the table rows.
 */
final readonly class EffectiveSettingCandidate
{
    public function __construct(
        public readonly int $id,
        public readonly DateTimeImmutable $effectiveFrom,
    ) {}
}
