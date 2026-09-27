<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use App\Modules\Shared\Contracts\CurrentUserProviderInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Append-only activity trail for every critical write (RF-AUD-001,
 * RNF-005, ADR-19).
 *
 * Mirrors the AuditableObserver pattern (ADR-14): a generic Shared
 * observer that each module registers against its models with one
 * Model::observe line, so the causer is resolved through the
 * CurrentUserProviderInterface port (DIP) and no module ever touches
 * another module's internals. Every created/updated/deleted/restored
 * event lands in the spatie activity_log table with:
 *
 *  - event + description  : what happened (append-only, RN-010)
 *  - subject morphs       : the affected row
 *  - causer morphs        : the acting user, when authenticated
 *  - properties.old       : previous values of the changed attributes
 *  - properties.attributes: new values of the changed attributes
 *  - properties.request_id: correlation id of the HTTP request
 *
 * The table is insert-only from the application: no update or delete
 * pathway exists anywhere in the codebase, and the audit query surface
 * (RF-AUD-003) is read-only by construction.
 */
final class AuditTrailObserver
{
    private const string USER_MODEL_KEY = 'auth.providers.users.model';

    public function __construct(
        private readonly CurrentUserProviderInterface $currentUser,
    ) {}

    public function created(Model $model): void
    {
        $this->record($model, 'created', [], $model->getAttributes());
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

        $this->record($model, 'updated', $previous, $changes);
    }

    public function deleted(Model $model): void
    {
        /** @var array<string, mixed> $original */
        $original = $model->getOriginal();

        $this->record($model, 'deleted', $original, []);
    }

    public function restored(Model $model): void
    {
        $this->record($model, 'restored', [], $model->getAttributes());
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $attributes
     */
    private function record(Model $model, string $event, array $old, array $attributes): void
    {
        $properties = [];

        if ($old !== []) {
            $properties['old'] = $old;
        }

        if ($attributes !== []) {
            $properties['attributes'] = $attributes;
        }

        $requestId = $this->currentRequestId();
        if ($requestId !== null) {
            $properties['request_id'] = $requestId;
        }

        $activity = new Activity;
        $activity->log_name = $this->logName();
        $activity->description = $event;
        $activity->event = $event;
        $activity->subject_type = $model->getMorphClass();
        $activity->subject_id = (int) $model->getKey();
        $activity->properties = new Collection($properties);

        $causerId = $this->currentUser->currentUserId();

        if ($causerId !== null) {
            $activity->causer_type = $this->causerType();
            $activity->causer_id = $causerId;
        }

        $activity->save();
    }

    /**
     * Correlation id for the running HTTP request, set by the
     * AssignRequestId middleware (architecture doc section 11);
     * null on CLI, seeders and other non-HTTP contexts.
     */
    private function currentRequestId(): ?string
    {
        $request = request();

        // The helper resolves the current request in HTTP context and
        // returns it (possibly an empty shell on CLI); the attribute
        // only exists after AssignRequestId ran for a real request.
        $id = $request->attributes->get('request_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Causer morph class resolved from configuration so Shared never
     * imports Security's model (deptrac keeps Shared dependency-free);
     * matches the class string the model's getMorphClass() reports.
     */
    private function causerType(): string
    {
        $model = config(self::USER_MODEL_KEY);

        assert(is_string($model) && $model !== '');

        return $model;
    }

    private function logName(): ?string
    {
        $name = config('activitylog.default_log_name');

        return is_string($name) && $name !== '' ? $name : null;
    }
}
