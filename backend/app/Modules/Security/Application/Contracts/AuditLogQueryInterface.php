<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\Contracts;

use App\Modules\Security\Application\DTO\AuditLogEntry;
use App\Modules\Security\Application\DTO\AuditLogFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read-only query port over the activity trail (RF-AUD-003, ADR-19).
 *
 * The bitácora is append-only (RF-AUD-001): this port deliberately
 * exposes a single listing operation — no mutation exists anywhere
 * in the application for activity rows. Controllers depend on this
 * abstraction (DIP, ADR-12) so the audit surface stays unit-testable
 * with a stub and the CSV export reuses the same query shape.
 */
interface AuditLogQueryInterface
{
    /**
     * @return LengthAwarePaginator<int, AuditLogEntry>
     */
    public function list(AuditLogFilters $filters): LengthAwarePaginator;
}
