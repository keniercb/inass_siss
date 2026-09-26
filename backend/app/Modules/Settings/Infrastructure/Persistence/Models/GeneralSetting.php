<?php

declare(strict_types=1);

namespace App\Modules\Settings\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for the versioned general settings (RF-CAT-005).
 *
 * Conventions (ADR-11): persistence lives in the module's
 * Infrastructure layer and the vigencia rules live in the Domain
 * resolver and Application service. Versions are immutable after
 * creation — corrections ship as new versions — so the table carries
 * no soft delete: history must remain reproducible for RN-007. The
 * effective_to attribute is NOT a column: the service derives it from
 * the next vigencia and attaches it before serialization. Authorship
 * is stamped by the Shared AuditableObserver (ADR-14) registered in
 * the SettingsServiceProvider, so this class imports no Security
 * types (deptrac: Settings depends on Shared only).
 *
 * @property int $id
 * @property int $min_work_years
 * @property int $min_age_men
 * @property int $min_age_women
 * @property int $base_calc_percent
 * @property int $max_calc_percent
 * @property int $annual_increase_percent
 * @property CarbonImmutable $effective_from
 * @property string|null $effective_to derived in the service, never stored
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class GeneralSetting extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'min_work_years',
        'min_age_men',
        'min_age_women',
        'base_calc_percent',
        'max_calc_percent',
        'annual_increase_percent',
        'effective_from',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_work_years' => 'integer',
            'min_age_men' => 'integer',
            'min_age_women' => 'integer',
            'base_calc_percent' => 'integer',
            'max_calc_percent' => 'integer',
            'annual_increase_percent' => 'integer',
            'effective_from' => 'immutable_date',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }
}
