<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;

/**
 * Eloquent model for the agency_types catalog (Tipos de agencia).
 *
 * Conventions (ADR-11): the model lives in the module's Infrastructure
 * layer and business rules live in the module's Application services.
 * The code is immutable after creation and rows are logically
 * deactivated through soft deletes (RF-CAT-001). Authorship is stamped
 * by the Shared AuditableObserver, so this class deliberately imports
 * no Security types (deptrac: Catalogs depends on Shared only).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $deleted_at
 */
class AgencyType extends CatalogModel
{
    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'created_by',
        'updated_by',
    ];
}
