<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Infrastructure\Persistence\Models;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\Municipality;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\OfficeType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Province;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Eloquent model for offices (RF-ENT-002, data model 5.5).
 *
 * Offices carry no natural key: they are identified by their type,
 * geographic scope and hierarchy position. The acyclicity rule
 * (RN-003) is resolved by the Domain HierarchyPolicy before
 * persisting and the geographic coherence (RN-004) is doubly
 * guaranteed by the composite key declared in the migration. The
 * Shared observers stamp authorship and land every write in the
 * append-only activity trail (ADR-14/ADR-19).
 *
 * @property int $id
 * @property int $office_type_id
 * @property int $province_id
 * @property int $municipality_id
 * @property string $address
 * @property int|null $parent_office_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property OfficeType|null $officeType
 * @property Province|null $province
 * @property Municipality|null $municipality
 * @property Office|null $parent
 */
class Office extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'office_type_id',
        'province_id',
        'municipality_id',
        'address',
        'parent_office_id',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'office_type_id' => 'integer',
            'province_id' => 'integer',
            'municipality_id' => 'integer',
            'parent_office_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    public function officeType(): BelongsTo
    {
        return $this->belongsTo(OfficeType::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    /**
     * Superior office; resolves against soft-deleted parents too.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_office_id');
    }
}
