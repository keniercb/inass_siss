<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\DTO;

use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * One activity row of the append-only bitácora, shaped for the read
 * surface (RF-AUD-003): who acted, on which row, what changed and
 * when. Values map 1:1 onto the spatie activity_log table; the
 * properties diff arrives split into previous and new values so the
 * Presentation layer never re-parses JSON.
 */
final class AuditLogEntry
{
    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $changes
     */
    public function __construct(
        public readonly int $id,
        public readonly string $event,
        public readonly string $description,
        public readonly ?int $causerId,
        public readonly ?string $subjectType,
        public readonly ?int $subjectId,
        public readonly ?array $old,
        public readonly ?array $changes,
        public readonly ?string $requestId,
        public readonly DateTimeImmutable $createdAt,
    ) {}

    public function occurredAt(): CarbonImmutable
    {
        return CarbonImmutable::instance($this->createdAt);
    }
}
