<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Infrastructure\Persistence\Models;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\Position;
use App\Modules\People\Infrastructure\Persistence\Models\Person;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Eloquent model for authorized signatures (RF-ENT-003, data model
 * 5.5).
 *
 * The entity+person+position tern is unique and stays reserved by
 * revoked (soft-deleted) rows: the revocation history cannot be
 * overwritten, only queried. The status of a signature is derived
 * from its optional validity window at read time (Domain
 * SignatureStatus) — never a stored column. The Shared observers
 * stamp authorship and land every write, including the revocation,
 * in the append-only activity trail (ADR-14/ADR-19).
 *
 * @property int $id
 * @property int $entity_id
 * @property int $person_id
 * @property int $position_id
 * @property CarbonImmutable|null $valid_from
 * @property CarbonImmutable|null $valid_to
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property Entity|null $entity
 * @property Person|null $person
 * @property Position|null $position
 */
class AuthorizedSignature extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'entity_id',
        'person_id',
        'position_id',
        'valid_from',
        'valid_to',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'person_id' => 'integer',
            'position_id' => 'integer',
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }
}
