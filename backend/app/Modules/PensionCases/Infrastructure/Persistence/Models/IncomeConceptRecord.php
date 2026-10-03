<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Infrastructure\Persistence\Models;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\IncomeConcept;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the declared income concepts of a case (user
 * rule 5, model data 5.7 sibling of salary_records): ONE value per
 * (case, concept) pair, DECIMAL(12,2) exact money (RN-005) plus the
 * percent to apply (Task 42, user correction, SGP-36) — a Double in
 * the user's words materialized as DECIMAL(5,2) with range 0-100 and
 * the database DEFAULT 0.00 covering only the writes outside the
 * wire (both write paths demand it: 422 when omitted, out of range
 * or carrying a third decimal).
 *
 * Conventions (ADR-11): no soft delete and no authorship columns —
 * the expediente is the aggregate and owns them; the Shared
 * AuditTrailObserver (registered in the PensionCasesServiceProvider)
 * lands every high/removal in the append-only bitácora with the
 * previous values (ADR-19).
 *
 * @property int $id
 * @property int $pension_case_id
 * @property int $income_concept_id
 * @property string $amount
 * @property string $applied_percent
 * @property IncomeConcept|null $incomeConcept
 */
class IncomeConceptRecord extends Model
{
    /** No timestamps: the bitácora keeps the values of every write. */
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'pension_case_id',
        'income_concept_id',
        'amount',
        'applied_percent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pension_case_id' => 'integer',
            'income_concept_id' => 'integer',
            'amount' => 'decimal:2',
            'applied_percent' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<IncomeConcept, $this>
     */
    public function incomeConcept(): BelongsTo
    {
        return $this->belongsTo(IncomeConcept::class);
    }
}
