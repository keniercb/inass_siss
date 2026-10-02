<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Infrastructure\Persistence\Models;

use App\Modules\PensionCases\Domain\CaseStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Eloquent model for pension cases (RF-EXP-001, model data 5.7).
 *
 * Conventions (ADR-11): persistence lives in the module's
 * Infrastructure layer; the creation, eligibility and subrecord
 * rules live in the Application service fed by the pure Domain
 * analysis values (SalarySeries, ServicePeriods). The number is
 * composed of the registering office's province and municipality
 * codes, the last two digits of the current year and the TERRITORIAL
 * consecutive of the shared sequence (PPMMAACCCCC, user rule
 * 2/ADR-34), the status is the normative
 * CaseStatus enum (section 2.4) and the one-open-case-per-person
 * guarantee is physical: the stored generated column `open_case_key`
 * is NULL on terminal states AND on soft-deleted rows (SGP-34: the
 * soft delete releases the one-open-case reservation) so the
 * UNIQUE index admits many resolved cases but at most one live
 * case per applicant. The
 * pension classification (type, regime, rebel army pair) rides
 * along since user rule 4 and the income concept records are a
 * (case, concept) unique subrecord (user rule 5). Since Task 35
 * (user correction over Task 34, renamed to English by Task 36) the
 * optional filed_by_person_id —
 * the REGISTERED person who files or manages the case when it is
 * not the applicant themselves — is a nullable reference to
 * people (restrictOnDelete, like every reference of the table)
 * probed against the ACTIVE registry surface before writing; the
 * full Person projection travels as the filedBy relation,
 * eager-loaded by the detail and the listing exactly like the
 * applicant. Since Task 37 (user correction, SGP-31) the case also
 * carries the internationalist flag of the promovente (parallel of
 * the rebel army pair, required at the wire with the database
 * DEFAULT covering off-wire writes) plus the promovente contact
 * pair — phone and popular_council, nullable free text. Since Task
 * 38 (user correction, SGP-32) the case also carries the promovente's
 * fecha de desvinculación — termination_date, a plain nullable date
 * with no semantic probe (the user correction declares it optional).
 * Authorship is stamped by the Shared AuditableObserver and every
 * write lands in the append-only trail through the Shared
 * AuditTrailObserver, both registered in the
 * PensionCasesServiceProvider.
 *
 * @property int $id
 * @property string $number
 * @property CarbonImmutable $requested_at
 * @property CaseStatus $status
 * @property int $applicant_person_id
 * @property int $office_id
 * @property int $employer_entity_id
 * @property int $position_id
 * @property int $occupational_category_id
 * @property int $educational_level_id
 * @property int $scientific_category_id
 * @property int $pension_type_id
 * @property int $pension_regime_id
 * @property string $last_salary
 * @property bool $rebel_army_member
 * @property CarbonImmutable|null $rebel_army_join_date
 * @property bool $internationalist
 * @property int|null $filed_by_person_id
 * @property string|null $phone
 * @property string|null $popular_council
 * @property CarbonImmutable|null $termination_date
 * @property int|null $approval_legal_basis_id
 * @property string|null $decision_notes
 * @property int|null $decided_by
 * @property CarbonInterface|null $decided_at
 * @property string|null $computed_amount
 * @property int|null $calculation_setting_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read Collection<int, SalaryRecord> $salaryRecords
 * @property-read Collection<int, ServiceRecord> $serviceRecords
 * @property-read Collection<int, WorkCycle> $workCycles
 * @property-read Collection<int, IncomeConceptRecord> $incomeConceptRecords
 * @property-read \App\Modules\People\Infrastructure\Persistence\Models\Person|null $applicant
 * @property-read \App\Modules\People\Infrastructure\Persistence\Models\Person|null $filedBy
 */
class PensionCase extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'number',
        'requested_at',
        'status',
        'applicant_person_id',
        'office_id',
        'employer_entity_id',
        'position_id',
        'occupational_category_id',
        'educational_level_id',
        'scientific_category_id',
        'pension_type_id',
        'pension_regime_id',
        'last_salary',
        'rebel_army_member',
        'rebel_army_join_date',
        'internationalist',
        'filed_by_person_id',
        'phone',
        'popular_council',
        'termination_date',
        'approval_legal_basis_id',
        'decision_notes',
        'decided_by',
        'decided_at',
        'computed_amount',
        'calculation_setting_id',
        'created_by',
        'updated_by',
    ];

    /**
     * Stored generated column (S5.1): never serialized, never
     * assignable — it only serves the one-open-case-per-person
     * UNIQUE index.
     *
     * @var list<string>
     */
    protected $hidden = ['open_case_key'];

    /** @var list<string> */
    protected $guarded = ['open_case_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_at' => 'immutable_date',
            'status' => CaseStatus::class,
            'last_salary' => 'decimal:2',
            'rebel_army_join_date' => 'immutable_date',
            'rebel_army_member' => 'boolean',
            'internationalist' => 'boolean',
            'termination_date' => 'immutable_date',
            'filed_by_person_id' => 'integer',
            'decided_at' => 'immutable_datetime',
            'computed_amount' => 'decimal:2',
            'approval_legal_basis_id' => 'integer',
            'decided_by' => 'integer',
            'calculation_setting_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SalaryRecord>
     */
    public function salaryRecords(): HasMany
    {
        return $this->hasMany(SalaryRecord::class)->orderBy('year');
    }

    /**
     * @return HasMany<ServiceRecord>
     */
    public function serviceRecords(): HasMany
    {
        return $this->hasMany(ServiceRecord::class)->orderBy('start_date');
    }

    /**
     * @return HasMany<WorkCycle>
     */
    public function workCycles(): HasMany
    {
        return $this->hasMany(WorkCycle::class)->orderBy('id');
    }

    /**
     * @return HasMany<IncomeConceptRecord>
     */
    public function incomeConceptRecords(): HasMany
    {
        return $this->hasMany(IncomeConceptRecord::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function applicant(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\People\Infrastructure\Persistence\Models\Person::class, 'applicant_person_id');
    }

    /**
     * The registered person who files or manages the case when it
     * is not the applicant themselves (Task 35, English column name
     * since Task 36): nullable reference
     * probed against the ACTIVE registry surface by the service —
     * a deactivated person is history, not a filer.
     *
     * @return BelongsTo<Person, $this>
     */
    public function filedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\People\Infrastructure\Persistence\Models\Person::class, 'filed_by_person_id');
    }
}
