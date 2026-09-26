<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use App\Modules\Shared\Contracts\CurrentUserProviderInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Automatic stamping of audit authorship columns (ADR-14).
 *
 * A model opts in by declaring created_by/updated_by as fillable
 * attributes. creating() fills created_by only while it is still null,
 * so explicit authors set by seeders or import jobs are preserved;
 * updating() always restamps updated_by with the acting user. On
 * anonymous context (CLI, seeders) both columns are left untouched,
 * never erasing recorded history.
 *
 * Registration happens in each module's service provider with
 * Model::observe(AuditableObserver::class): Laravel resolves the
 * observer through the container on every model event, which injects
 * the CurrentUserProviderInterface port (DIP, architecture doc
 * section 7).
 */
final class AuditableObserver
{
    public function __construct(
        private readonly CurrentUserProviderInterface $currentUser,
    ) {}

    public function creating(Model $model): void
    {
        if (! $model->isFillable('created_by')) {
            return;
        }

        $author = $this->currentUser->currentUserId();

        if ($author !== null && $model->getAttribute('created_by') === null) {
            $model->setAttribute('created_by', $author);
        }
    }

    public function updating(Model $model): void
    {
        if (! $model->isFillable('updated_by')) {
            return;
        }

        $author = $this->currentUser->currentUserId();

        if ($author !== null) {
            $model->setAttribute('updated_by', $author);
        }
    }
}
