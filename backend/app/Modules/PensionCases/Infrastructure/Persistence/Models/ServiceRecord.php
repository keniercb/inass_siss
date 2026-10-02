<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Infrastructure\Persistence\Models;

use App\Modules\Organizations\Infrastructure\Persistence\Models\Entity;
use App\Modules\PensionCases\Domain\ServiceDeclarationForm;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the work history rows (RF-EXP-003, model data
 * 5.7). Since the Task 37 user correction every period is CLOSED:
 * the end date is mandatory and STRICTLY posterior to the start —
 * backed by the database CHECK — and no two rows of a case may
 * share a day, a rule the pure Domain ServicePeriods analysis
 * enforces as a 422 at both entry points. No timestamps: the bitacora
 * keeps the values of every high and removal through the Shared
 * AuditTrailObserver.
 *
 * @property int $id
 * @property int $pension_case_id
 * @property int $entity_id
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property bool $is_appendix
 * @property ServiceDeclarationForm $declaration_form
 * @property Entity|null $entity
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
        'declaration_form',
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
            'declaration_form' => ServiceDeclarationForm::class,
        ];
    }

    /**
     * @return BelongsTo<PensionCase, $this>
     */
    public function pensionCase(): BelongsTo
    {
        return $this->belongsTo(PensionCase::class);
    }

    /**
     * Employer entity the link was declared against: the service
     * listing answers its full projection (user rule). Resolves
     * null against a soft-deleted entity — deactivated history is
     * surfaced, never hidden.
     *
     * @return BelongsTo<Entity, $this>
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
