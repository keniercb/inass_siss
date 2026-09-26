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
 * sequences consumed by later phases — bank control numbers (phase 5)
 * and pension case numbers (phase 3). Idempotent on the natural scope
 * key: re-running never duplicates rows and never rewinds a consumed
 * sequence (RN-009: emitted numbers stay burned).
 */
final class SettingsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_declared_sequences_without_resetting_consumed_values(): void
    {
        $this->artisan('db:seed', ['--class' => SettingsSeeder::class]);

        $this->assertSame(
            ['bank_control', 'pension_case'],
            NumberingSequence::query()
                ->whereIn('scope', ['bank_control', 'pension_case'])
                ->orderBy('scope')
                ->pluck('scope')
                ->all(),
        );
        $this->assertSame(
            1,
            (int) NumberingSequence::query()->where('scope', 'bank_control')->value('next_value'),
        );
        $this->assertSame(
            1,
            (int) NumberingSequence::query()->where('scope', 'pension_case')->value('next_value'),
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
}
