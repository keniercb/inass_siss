<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

/**
 * Models that carry secret material (password hashes, tokens) inside
 * audited columns implement this contract so the generic audit trail
 * never copies those values into the append-only bitácora (ADR-24).
 *
 * The observer replaces every listed attribute with a [redacted]
 * marker in BOTH the old and the new snapshot: the fact that the
 * secret changed stays auditable, the secret itself never enters a
 * log that every Auditor can read (RF-AUD-003 vs RF-SEG-001).
 */
interface RedactsAuditAttributes
{
    /**
     * Attribute names whose values must never appear in the audit
     * trail, in any event (created, updated, deleted, restored).
     *
     * @return list<string>
     */
    public function auditRedactedAttributes(): array;
}
