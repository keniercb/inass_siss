<?php

declare(strict_types=1);

namespace App\Modules\People\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Eloquent model for people (RF-PER-001..005, model data 5.4).
 *
 * Conventions (ADR-11): persistence lives in the module's
 * Infrastructure layer; the duplicate and lifecycle rules live in the
 * Domain policy and the Application service. The identity number is
 * unique and immutable (RN-001) and the sex/birth-death ordering is
 * guaranteed by CHECK constraints in the database. Authorship is
 * stamped by the Shared AuditableObserver (ADR-14) and every write
 * lands in the append-only activity trail through the Shared
 * AuditTrailObserver (ADR-19), both registered in the
 * PeopleServiceProvider — this class imports no Security types
 * (deptrac: People depends on Shared and Catalogs only). Deactivation
 * is a soft delete: the registry keeps the person (and her identity
 * stays reserved, RN-001) while searches and detail views exclude
 * her.
 *
 * @property int $id
 * @property string $identity_number
 * @property string $first_name
 * @property string|null $middle_name
 * @property string $first_surname
 * @property string|null $second_surname
 * @property string $sex
 * @property int|null $race_id
 * @property string $address
 * @property CarbonImmutable $birth_date
 * @property CarbonImmutable|null $death_date
 * @property string|null $father_name
 * @property string|null $mother_name
 * @property string|null $citizen_card_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class Person extends Model
{
    /**
     * @use HasFactory<PersonFactory>
     */
    use HasFactory, SoftDeletes;

    /**
     * Explicit because the factory lives in Database\Factories while
     * this model lives inside the People module, so name-based
     * discovery does not apply (ADR-11, same as User).
     */
    protected static function newFactory(): Factory
    {
        return PersonFactory::new();
    }

    /** @var list<string> */
    protected $fillable = [
        'identity_number',
        'first_name',
        'middle_name',
        'first_surname',
        'second_surname',
        'sex',
        'race_id',
        'address',
        'birth_date',
        'death_date',
        'father_name',
        'mother_name',
        'citizen_card_id',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'race_id' => 'integer',
            'birth_date' => 'immutable_date',
            'death_date' => 'immutable_date',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }
}
