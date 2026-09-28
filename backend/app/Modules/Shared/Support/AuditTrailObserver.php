<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only activity trail for every critical write (RF-AUD-001,
 * RNF-005, ADR-19).
 *
 * Mirrors the AuditableObserver pattern (ADR-14): a generic Shared
 * observer that each module registers against its models with one
 * Model::observe line, so the causer is resolved through the
 * CurrentUserProviderInterface port (DIP) and no module ever touches
 * another module's internals. Every created/updated/deleted/restored
 * event lands in the spatie activity_log table through the shared
 * AuditRecorder (one entry shape for every caller, ADR-24) with
 * secret columns redacted (RedactsAuditAttributes).
 *
 * The table is insert-only from the application: no update or delete
 * pathway exists anywhere in the codebase, and the audit query surface
 * (RF-AUD-003) is read-only by construction.
 */
final class AuditTrailObserver
{
    public function __construct(
        private readonly AuditRecorder $recorder,
    ) {}

    public function created(Model $model): void
    {
        $this->recorder->record($model, 'created', 'created', [], $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        $changes = $model->getChanges();

        if ($changes === []) {
            return;
        }

        $previous = [];
        foreach (array_keys($changes) as $key) {
            $previous[$key] = $model->getOriginal($key);
        }

        $this->recorder->record($model, 'updated', 'updated', $previous, $changes);
    }

    public function deleted(Model $model): void
    {
        /** @var array<string, mixed> $original */
        $original = $model->getOriginal();

        $this->recorder->record($model, 'deleted', 'deleted', $original, []);
    }

    public function restored(Model $model): void
    {
        $this->recorder->record($model, 'restored', 'restored', [], $model->getAttributes());
    }
}
