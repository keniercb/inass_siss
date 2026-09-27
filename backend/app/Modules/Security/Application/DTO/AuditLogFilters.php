<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\DTO;

use DateTimeImmutable;

/**
 * Read-only filter set for the audit trail query (RF-AUD-003).
 *
 * Presentation builds it from validated query parameters; the query
 * port applies it as exact matches plus an inclusive created_at range.
 * Dates arrive as immutable day boundaries so Infrastructure can
 * close the "to" side without leaking date arithmetic around.
 */
final class AuditLogFilters
{
    public function __construct(
        public readonly ?int $causerId = null,
        public readonly ?string $subjectType = null,
        public readonly ?int $subjectId = null,
        public readonly ?string $event = null,
        public readonly ?DateTimeImmutable $from = null,
        public readonly ?DateTimeImmutable $to = null,
        public readonly int $page = 1,
        public readonly int $perPage = 15,
    ) {}
}
