<?php

declare(strict_types=1);

namespace App\Modules\Security\Infrastructure\Audit;

use App\Modules\Security\Application\Contracts\AuditLogQueryInterface;
use App\Modules\Security\Application\DTO\AuditLogEntry;
use App\Modules\Security\Application\DTO\AuditLogFilters;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Eloquent adapter over the spatie activity_log table (ADR-19).
 *
 * Read-only by construction: the single statement builder never
 * issues UPDATE or DELETE, mirroring the append-only contract of
 * RF-AUD-001. Filters map to exact matches plus an inclusive
 * created_at range closed at the end of the "to" day.
 */
final class EloquentAuditLogQuery implements AuditLogQueryInterface
{
    public function list(AuditLogFilters $filters): LengthAwarePaginator
    {
        $query = Activity::query()
            ->orderByDesc('id');

        if ($filters->causerId !== null) {
            $query->where('causer_id', $filters->causerId);
        }

        if ($filters->subjectType !== null) {
            $query->where('subject_type', $filters->subjectType);
        }

        if ($filters->subjectId !== null) {
            $query->where('subject_id', $filters->subjectId);
        }

        if ($filters->event !== null) {
            $query->where('event', $filters->event);
        }

        if ($filters->from !== null) {
            $query->where('created_at', '>=', $filters->from);
        }

        if ($filters->to !== null) {
            $query->where('created_at', '<=', $filters->to->modify('+1 day -1 second'));
        }

        $paginator = $query->paginate($filters->perPage, ['*'], 'page', $filters->page);

        $entries = $paginator
            ->getCollection()
            ->map(fn (Activity $activity): AuditLogEntry => $this->toEntry($activity));

        return $paginator->setCollection(new Collection($entries->all()));
    }

    private function toEntry(Activity $activity): AuditLogEntry
    {
        /** @var Collection<array-key, mixed>|null $properties */
        $properties = $activity->properties;

        $old = $properties?->get('old');
        $changes = $properties?->get('attributes');
        $requestId = $properties?->get('request_id');

        /** @var Carbon|null $createdAt */
        $createdAt = $activity->created_at;

        return new AuditLogEntry(
            id: (int) $activity->id,
            event: (string) ($activity->event ?? $activity->description),
            description: $activity->description,
            causerId: $activity->causer_id !== null ? (int) $activity->causer_id : null,
            subjectType: $activity->subject_type,
            subjectId: $activity->subject_id !== null ? (int) $activity->subject_id : null,
            old: is_array($old) ? $old : null,
            changes: is_array($changes) ? $changes : null,
            requestId: is_string($requestId) ? $requestId : null,
            createdAt: $createdAt !== null ? $createdAt->toDateTimeImmutable() : now()->toDateTimeImmutable(),
        );
    }
}
