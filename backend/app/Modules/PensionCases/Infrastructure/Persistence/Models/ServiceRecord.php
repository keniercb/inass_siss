<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the work history rows (RF-EXP-003, model data
 * 5.7). End date NULL means the employment link is still open; the
 * date order is backed by a database CHECK (RN-006) and overlaps
 * are detected and advertised by the pure Domain ServicePeriods
 * analysis. No timestamps: the bitácora keeps the values of every
 * high and removal through the Shared AuditTrailObserver.
 *
 * @property int $id
 * @property int $pension_case_id
 * @property int $entity_id
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable|null $end_date
 * @property bool $is_appendix
 */
class ServiceRecord extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'pension_case_id',
        'entity_id',
        'start_date',
        'end_date',
        'is_appendix',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'is_appendix' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PensionCase, $this>
     */
    public function pensionCase(): BelongsTo
    {
        return $this->belongsTo(PensionCase::class);
    }
}
