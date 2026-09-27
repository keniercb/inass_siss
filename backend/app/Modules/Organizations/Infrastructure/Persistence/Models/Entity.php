<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Infrastructure\Persistence\Models;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\EntityType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Municipality;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Organization;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Province;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Eloquent model for entities (RF-ENT-001, data model 5.5).
 *
 * Conventions (ADR-11): persistence lives in the module's
 * Infrastructure layer; the acyclicity rule (RN-003) is resolved by
 * the Domain HierarchyPolicy before persisting because no database
 * constraint can express it, and the geographic coherence (RN-004)
 * is doubly guaranteed by the composite key declared in the
 * migration. Code and NIT are unique and immutable, and a soft
 * deleted entity keeps both reserved. Authorship is stamped by the
 * Shared AuditableObserver and every write lands in the append-only
 * activity trail through the Shared AuditTrailObserver, both
 * registered in the OrganizationsServiceProvider — this class
 * imports no Security types (deptrac: Organizations depends on
 * Shared, Catalogs and People only).
 *
 * @property int $id
 * @property string $code
 * @property string $tax_id_number
 * @property int $organization_id
 * @property int $province_id
 * @property int $municipality_id
 * @property int $entity_type_id
 * @property string $address
 * @property string|null $phone
 * @property string|null $fax
 * @property string|null $email
 * @property int|null $director_person_id
 * @property int|null $economic_director_person_id
 * @property int|null $parent_entity_id
 * @property string $social_purpose
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property Organization|null $organization
 * @property Province|null $province
 * @property Municipality|null $municipality
 * @property EntityType|null $entityType
 * @property Entity|null $parent
 */
class Entity extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'tax_id_number',
        'organization_id',
        'province_id',
        'municipality_id',
        'entity_type_id',
        'address',
        'phone',
        'fax',
        'email',
        'director_person_id',
        'economic_director_person_id',
        'parent_entity_id',
        'social_purpose',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'province_id' => 'integer',
            'municipality_id' => 'integer',
            'entity_type_id' => 'integer',
            'director_person_id' => 'integer',
            'economic_director_person_id' => 'integer',
            'parent_entity_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function entityType(): BelongsTo
    {
        return $this->belongsTo(EntityType::class);
    }

    /**
     * Superior entity. The relation resolves against soft-deleted
     * parents too: a deactivated superior leaves the subtree pointing
     * at history, which the service surfaces instead of hiding.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_entity_id');
    }
}
