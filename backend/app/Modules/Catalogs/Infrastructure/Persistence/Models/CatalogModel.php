<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Base class for every catalog model of the Catalogs module.
 *
 * Centralizes the conventions shared by all 18 catalog tables (data
 * model sections 5.1/5.2): authorship columns stamped by the Shared
 * AuditableObserver (ADR-14) and logical deactivation implemented as
 * Laravel soft deletes (RF-CAT-001: catalog entries are never
 * physically removed).
 *
 * Deliberately declares no creator()/updater() Eloquent relations: the
 * users table belongs to the Security module and deptrac forbids
 * Catalogs from importing Security types (Catalogs depends on Shared
 * only). Authorship is exposed as plain integer ids; the human
 * readable trail lives in the audit log (RF-AUD-001).
 *
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $deleted_at
 */
abstract class CatalogModel extends Model
{
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }
}
