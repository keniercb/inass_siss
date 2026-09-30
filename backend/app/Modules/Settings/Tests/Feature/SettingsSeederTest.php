<?php

declare(strict_types=1);

namespace App\Modules\Settings\Tests\Feature;

use App\Modules\Settings\Infrastructure\Persistence\Models\NumberingSequence;
use App\Modules\Shared\Contracts\SequenceGeneratorInterface;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SettingsSeeder (architecture section 8): declares the centralized
 * sequences consumed by declared-scope consumers — bank control
 * numbers (phase 5). The pension case consecutive needs NO
 * pre-declaration since ADR-34: case numbers consume one row per
 * YEAR, PROVINCE and MUNICIPALITY, and the generator births each
 * scope on its first emission, so neither the year nor the
 * territorial rollover needs an operator. Idempotent on the natural
 * scope key: re-running never duplicates rows and never rewinds a
 * consumed sequence (RN-009: emitted numbers stay burned).
 */
final class SettingsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_declared_sequences_without_resetting_consumed_values(): void
    {
        $this->artisan('db:seed', ['--class' => SettingsSeeder::class]);

        $this->assertTrue(
            NumberingSequence::query()->where('scope', 'bank_control')->exists(),
        );
        $this->assertSame(
            1,
            (int) NumberingSequence::query()->where('scope', 'bank_control')->value('next_value'),
        );

        $generator = $this->app->make(SequenceGeneratorInterface::class);

        // Consume one number: it is burned forever.
        $this->assertSame(1, $generator->next('bank_control'));

        // Re-running the seeder must never rewind the sequence.
        $this->artisan('db:seed', ['--class' => SettingsSeeder::class]);

        $this->assertSame(
            2,
            (int) NumberingSequence::query()->where('scope', 'bank_control')->value('next_value'),
        );
        $this->assertSame(2, $generator->next('bank_control'));
    }

    public function test_no_pension_case_scope_is_pre_declared(): void
    {
        // ADR-34: the case consecutive is territorial — one row per
        // (year, province, municipality) — so the seeder must not
        // guess which territories will exist: every scope row is born
        // at its first emission. The table also carries scopes other
        // suites emitted on the dedicated connection, so the probe
        // compares BEFORE and AFTER instead of assuming emptiness.
        $before = NumberingSequence::query()
            ->where('scope', 'like', 'pension_case%')
            ->pluck('scope')
            ->all();

        $this->artisan('db:seed', ['--class' => SettingsSeeder::class]);

        $this->assertSame(
            $before,
            NumberingSequence::query()
                ->where('scope', 'like', 'pension_case%')
                ->pluck('scope')
                ->all(),
        );

        $year = (int) now()->format('Y');
        $generator = $this->app->make(SequenceGeneratorInterface::class);

        // The first emission of a territory births its row at one.
        $this->assertSame(1, $generator->nextForTerritory('pension_case', $year, '11', '03'));
        $this->assertSame(
            2,
            (int) NumberingSequence::query()->where('scope', 'pension_case:'.$year.':11:03')->value('next_value'),
        );
    }
}
