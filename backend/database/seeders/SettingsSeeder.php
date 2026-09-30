<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Settings\Infrastructure\Persistence\Models\NumberingSequence;
use Illuminate\Database\Seeder;

/**
 * Declares the centralized sequences consumed by later phases
 * (architecture section 8): bank control numbers (RF-PAG-001, phase 5)
 * and the ANNUAL pension case consecutive (phase 3, ADR-32: the case
 * number is PP-YYYY-CCCCC, so each year carries its own scope row
 * `pension_case:{year}`).
 *
 * Idempotent on the natural scope key: firstOrCreate never duplicates
 * rows AND never rewinds next_value on re-execution — a consumed
 * sequence stays consumed (RN-009: emitted numbers are burned
 * forever). The rows are written through the dedicated sequences
 * session, so seeding commits independently of any caller.
 *
 * The seeder declares the CURRENT year's row; the generator births
 * future years on their first emission (insertOrIgnore inside the
 * pessimistic-lock transaction), so the annual rollover needs no
 * operator and no re-seeding.
 */
final class SettingsSeeder extends Seeder
{
    private const string BANK_CONTROL_SCOPE = 'bank_control';

    private const string PENSION_CASE_BASE_SCOPE = 'pension_case';

    public function run(): void
    {
        $scopes = [
            self::BANK_CONTROL_SCOPE,
            self::PENSION_CASE_BASE_SCOPE.':'.now()->format('Y'),
        ];

        foreach ($scopes as $scope) {
            NumberingSequence::query()->firstOrCreate(
                ['scope' => $scope],
                ['next_value' => 1],
            );
        }
    }
}
