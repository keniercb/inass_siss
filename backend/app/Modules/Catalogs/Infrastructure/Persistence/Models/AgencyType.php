<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;

/**
 * Eloquent model for the agency_types catalog (Tipos de agencia).
 *
 * Conventions (ADR-11): the model lives in the module's Infrastructure
 * layer and business rules live in the module's Application services.
 * The code is immutable after creation and rows are logically
 * deactivated through soft deletes (RF-CAT-001). Authorship is stamped
 * by the Shared AuditableObserver, so this class deliberately imports
 * no Security types (deptrac: Catalogs depends on Shared only).
 *
 * Task 42 (user correction, SGP-36): the type carries the payment form
 * of the collection — payment_form, the lowercase-unified enum
 * 'tarjeta magnetica'|'nomina electronica' (written exactly as the
 * user fixed it, no tildes) with database DEFAULT 'tarjeta magnetica'.
 * The in-memory default mirrors the column default so an omitted
 * store payload answers 'tarjeta magnetica' in the 201 projection
 * without a reload (the PensionType/deceased_person precedent of
 * Task 38), and the value decides the conditional bank-account demand
 * of the case's collection group.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $payment_form
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $deleted_at
 */
class AgencyType extends CatalogModel
{
    /**
     * Task 42: the in-memory default mirrors the database DEFAULT, so
     * a store payload that omits the payment form answers
     * 'tarjeta magnetica' in the 201 projection without a reload — the
     * same observable contract the column default gives the stored
     * row (the PensionType/deceased_person precedent of Task 38).
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'payment_form' => 'tarjeta magnetica',
    ];

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        // Task 42 (user correction, SGP-36): payment form of the
        // collection.
        'payment_form',
        'created_by',
        'updated_by',
    ];
}
