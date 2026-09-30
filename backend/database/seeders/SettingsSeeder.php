<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Settings\Infrastructure\Persistence\Models\NumberingSequence;
use Illuminate\Database\Seeder;

/**
 * Declares the centralized sequences consumed by declared-scope
 * consumers (architecture section 8): bank control numbers
 * (RF-PAG-001, phase 5). The pension case consecutive needs NO
 * pre-declaration since ADR-34 — case numbers consume one row per
 * YEAR, PROVINCE and MUNICIPALITY (`pension_case:{year}:{province}:
 * {municipality}`), and the generator births each scope row on its
 * first emission (insertOrIgnore inside the pessimistic-lock
 * transaction), so neither the year nor the territorial rollover
 * needs an operator and no re-seeding.
 *
 * Idempotent on the natural scope key: firstOrCreate never duplicates
 * rows AND never rewinds next_value on re-execution — a consumed
 * sequence stays consumed (RN-009: emitted numbers are burned
 * forever). The rows are written through the dedicated sequences
 * session, so seeding commits independently of any caller.
 */
final class SettingsSeeder extends Seeder
{
    private const string BANK_CONTROL_SCOPE = 'bank_control';

    public function run(): void
    {
        NumberingSequence::query()->firstOrCreate(
            ['scope' => self::BANK_CONTROL_SCOPE],
            ['next_value' => 1],
        );
    }
}
