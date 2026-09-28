<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Domain;

/**
 * One declared work service as the domain sees it for analysis
 * (RF-EXP-003): identity, period and the coletilla marker. End date
 * NULL means the employment link is still open and therefore extends
 * towards the future.
 */
final class DeclaredService
{
    public function __construct(
        public readonly int $id,
        public readonly string $startDate,
        public readonly ?string $endDate,
        public readonly bool $isAppendix = false,
    ) {}
}
