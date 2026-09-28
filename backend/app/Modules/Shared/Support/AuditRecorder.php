<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use App\Modules\Shared\Contracts\CurrentUserProviderInterface;
use App\Modules\Shared\Contracts\RedactsAuditAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Single writer of the append-only activity trail (RF-AUD-001,
 * RNF-005, ADR-19/ADR-24).
 *
 * Extracted from the AuditTrailObserver so two kinds of callers share
 * ONE code path and therefore ONE entry shape: the generic Eloquent
 * observer (model lifecycle events) and modules that own writes the
 * ORM cannot see (role pivots, spatie syncs). Every entry records:
 *
 *  - event + description  : what happened (append-only, RN-010)
 *  - subject morphs       : the affected row
 *  - causer morphs        : the acting user, when authenticated
 *  - properties.old       : previous values of the changed attributes
 *  - properties.attributes: new values of the changed attributes
 *  - properties.request_id: correlation id of the HTTP request
 *
 * Models implementing RedactsAuditAttributes get their secret columns
 * replaced with [redacted] BEFORE the snapshot is built: password
 * hashes and tokens never reach a log readable by the Auditor.
 */
final class AuditRecorder
{
    private const string USER_MODEL_KEY = 'auth.providers.users.model';

    private const string REDACTED = '[redacted]';

    public function __construct(
        private readonly CurrentUserProviderInterface $currentUser,
    ) {}

    /**
     * Appends one trail entry for the given model event.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $attributes
     */
    public function record(Model $model, string $event, string $description, array $old, array $attributes): void
    {
        if ($model instanceof RedactsAuditAttributes) {
            foreach ($model->auditRedactedAttributes() as $secret) {
                if (array_key_exists($secret, $old)) {
                    $old[$secret] = self::REDACTED;
                }

                if (array_key_exists($secret, $attributes)) {
                    $attributes[$secret] = self::REDACTED;
                }
            }
        }

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
        $activity->description = $description;
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
