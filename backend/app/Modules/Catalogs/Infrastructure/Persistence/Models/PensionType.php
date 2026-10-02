<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;

/**
 * Eloquent model for the pension_types catalog (Tipos de pensión).
 *
 * Conventions (ADR-11): the model lives in the module's Infrastructure
 * layer and business rules live in the module's Application services.
 * The code is immutable after creation and rows are logically
 * deactivated through soft deletes (RF-CAT-001). Authorship is stamped
 * by the Shared AuditableObserver, so this class deliberately imports
 * no Security types (deptrac: Catalogs depends on Shared only).
 *
 * Since Task 38 (user correction, SGP-32) the type carries the persona
 * fallecida flag — deceased_person, a boolean whose database DEFAULT
 * false answers every store payload that omits it, exactly like the
 * applies_base_salary precedent of the income concepts.
 *
 * @property int $id
 * @property string|null $code
 * @property string $name
 * @property bool $deceased_person
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $deleted_at
 */
class PensionType extends CatalogModel
{
    /**
     * Task 38: the in-memory default mirrors the database DEFAULT, so
     * a store payload that omits the flag answers false in the 201
     * projection without a reload — the same observable contract the
     * column default gives the stored row.
     *
     * @var array<string, bool>
     */
    protected $attributes = [
        'deceased_person' => false,
    ];

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        // Task 38 (user correction, SGP-32): persona fallecida flag.
        'deceased_person',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'deceased_person' => 'boolean',
        ]);
    }
}
