<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Settings\Infrastructure\Persistence\Models\NumberingSequence;
use Illuminate\Database\Seeder;

/**
 * Declares the centralized sequences consumed by later phases
 * (architecture section 8): bank control numbers (RF-PAG-001, phase 5)
 * and pension case numbers (phase 3).
 *
 * Idempotent on the natural scope key: firstOrCreate never duplicates
 * rows AND never rewinds next_value on re-execution — a consumed
 * sequence stays consumed (RN-009: emitted numbers are burned
 * forever). The rows are written through the dedicated sequences
 * session, so seeding commits independently of any caller.
 */
final class SettingsSeeder extends Seeder
{
    /** @var list<non-empty-string> */
    private const SCOPES = [
        'bank_control',
        'pension_case',
    ];

    public function run(): void
    {
        foreach (self::SCOPES as $scope) {
            NumberingSequence::query()->firstOrCreate(
                ['scope' => $scope],
                ['next_value' => 1],
            );
        }
    }
}
