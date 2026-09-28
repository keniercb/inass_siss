<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the work cycle rows (RF-EXP-004, model data
 * 5.7). Days and counts are non-negative integers (UNSIGNED as the
 * last line) that the calculation engine will consume per regime
 * (RF-CAL-002, phase 4). No timestamps: the bitácora keeps the
 * values of every high and removal.
 *
 * @property int $id
 * @property int $pension_case_id
 * @property int $planned_days
 * @property int $actual_days
 * @property int $cycles_count
 */
class WorkCycle extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'pension_case_id',
        'planned_days',
        'actual_days',
        'cycles_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'planned_days' => 'integer',
            'actual_days' => 'integer',
            'cycles_count' => 'integer',
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
