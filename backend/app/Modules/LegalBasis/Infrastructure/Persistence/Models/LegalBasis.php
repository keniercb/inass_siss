<?php

declare(strict_types=1);

namespace App\Modules\LegalBasis\Infrastructure\Persistence\Models;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\LegalBasisType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Organization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Eloquent model for legal bases (RF-LEG-002..004, data model 5.6).
 *
 * Conventions (ADR-11): persistence lives in the module's
 * Infrastructure layer. The type+number+year tern is the natural
 * identity: unique (including soft-deleted rows, which keep it
 * reserved) and immutable after creation, with the year DERIVED from
 * issue_date (H-11) — never a client field. The date ordering
 * (RN-006) is guaranteed by CHECK constraints plus the service
 * validation, and whether the norm is in force is derived at read
 * time through the Domain LegalBasisStatus — never stored. Authorship
 * is stamped by the Shared AuditableObserver and every write lands in
 * the append-only activity trail through the Shared
 * AuditTrailObserver, both registered in the
 * LegalBasisServiceProvider — this class imports no Security types
 * (deptrac: LegalBasis depends on Shared and Catalogs only).
 *
 * @property int $id
 * @property int $legal_basis_type_id
 * @property string $number
 * @property CarbonImmutable $issue_date
 * @property CarbonImmutable $effective_date
 * @property CarbonImmutable|null $derogation_date
 * @property int $issuing_organization_id
 * @property int $year
 * @property string|null $reference
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property LegalBasisType|null $type
 * @property Organization|null $organization
 */
class LegalBasis extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'legal_basis_type_id',
        'number',
        'issue_date',
        'effective_date',
        'derogation_date',
        'issuing_organization_id',
        'year',
        'reference',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'legal_basis_type_id' => 'integer',
            'issue_date' => 'immutable_date',
            'effective_date' => 'immutable_date',
            'derogation_date' => 'immutable_date',
            'issuing_organization_id' => 'integer',
            'year' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LegalBasisType::class, 'legal_basis_type_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'issuing_organization_id');
    }
}
