<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the municipalities table (RF-CAT-002).
 *
 * The (province_id, code) pair is the natural key (RN-008); the
 * nullable province models the special municipality Isla de la
 * Juventud. Business rules live in the MunicipalityService behind the
 * MunicipalityServiceInterface port (ADR-11/12).
 *
 * @property int $id
 * @property int|null $province_id
 * @property string $code
 * @property string $name
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $deleted_at
 * @property Province|null $province
 */
class Municipality extends CatalogModel
{
    /** @var list<string> */
    protected $fillable = [
        'province_id',
        'code',
        'name',
        'created_by',
        'updated_by',
    ];

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }
}
