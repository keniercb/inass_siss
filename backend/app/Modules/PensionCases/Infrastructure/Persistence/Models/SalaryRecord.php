<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the yearly salary series rows (RF-EXP-002,
 * model data 5.7). No timestamps and no authorship of their own:
 * the case is the aggregate root, the (case, year) pair is UNIQUE
 * and the bitácora keeps the values of every high and removal
 * through the Shared AuditTrailObserver registered in the
 * PensionCasesServiceProvider.
 *
 * @property int $id
 * @property int $pension_case_id
 * @property int $year
 * @property string $earned_salary
 */
class SalaryRecord extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'pension_case_id',
        'year',
        'earned_salary',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'earned_salary' => 'decimal:2',
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
