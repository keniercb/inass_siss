<?php

declare(strict_types=1);

namespace App\Modules\Settings\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model of a numbering sequence row (RF-PAG-006, ADR-17).
 *
 * BOUND to the dedicated "sequences" database session: every read and
 * write of this model opens a connection that is never the caller's
 * business transaction, so sequence increments commit independently
 * (RN-009 never-reuse semantics). Rows are declared up front by the
 * SettingsSeeder and mutated only by the increment of the
 * MysqlSequenceGenerator — there is no update or delete surface, no
 * soft deletes and no authorship columns: this is transactional
 * state, not business history. The model deliberately has no
 * AuditableObserver (ADR-14 stamps business authorship, not
 * operational counters).
 *
 * @property int $id
 * @property string $scope
 * @property int $next_value
 */
class NumberingSequence extends Model
{
    /**
     * Database session used by every sequence operation. Cloned from
     * the default MySQL connection by the SettingsServiceProvider
     * (same server, same schema, independent session).
     */
    public const CONNECTION_NAME = 'sequences';

    protected $connection = 'sequences';

    /** @var list<string> */
    protected $fillable = [
        'scope',
        'next_value',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'next_value' => 'integer',
    ];
}
