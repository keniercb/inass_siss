<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Domain;

/**
 * One declared work service as the domain sees it for analysis
 * (RF-EXP-003): identity, period and the coletilla marker. Since the
 * Task 37 user correction every period is CLOSED — the end date is
 * mandatory and strictly posterior to the start — so the endDate is a
 * plain string, never null: the open link (fin NULL = vínculo
 * vigente) of Sprint 5 no longer exists.
 */
final class DeclaredService
{
    public function __construct(
        public readonly int $id,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly bool $isAppendix = false,
    ) {}
}
