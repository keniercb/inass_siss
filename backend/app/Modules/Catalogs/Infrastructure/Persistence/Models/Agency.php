<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the agencies table (RF-CAT-003).
 *
 * The municipality-province coherence (RN-04) is enforced in the
 * database by the composite foreign key declared in the migration;
 * the AgencyService additionally validates it upfront to answer with
 * a semantic 422 instead of a raw driver error.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $province_id
 * @property int $municipality_id
 * @property int $agency_type_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $deleted_at
 * @property Province|null $province
 * @property Municipality|null $municipality
 * @property AgencyType|null $agencyType
 */
class Agency extends CatalogModel
{
    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'province_id',
        'municipality_id',
        'agency_type_id',
        'created_by',
        'updated_by',
    ];

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function agencyType(): BelongsTo
    {
        return $this->belongsTo(AgencyType::class);
    }
}
