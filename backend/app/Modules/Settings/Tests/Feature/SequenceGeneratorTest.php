<?php

declare(strict_types=1);

namespace App\Modules\Settings\Tests\Feature;

use App\Modules\Settings\Infrastructure\Persistence\Models\NumberingSequence;
use App\Modules\Shared\Contracts\SequenceGeneratorInterface;
use App\Modules\Shared\Exceptions\UnknownSequenceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use RuntimeException;
use Tests\TestCase;

/**
 * Concurrency-safe sequence emission (RF-PAG-006, RN-009, ADR-17).
 *
 * The MySQL adapter of the Shared port emits each value inside a
 * pessimistic-lock transaction (SELECT ... FOR UPDATE) that commits on
 * a dedicated database session, independent of the caller's business
 * transaction: when business data rolls back the emitted number stays
 * burned (gaps are accepted, reuse never happens). Sequences must be
 * declared up front — an undeclared scope fails loudly instead of
 * silently creating rows.
 */
final class SequenceGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private SequenceGeneratorInterface $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = $this->app->make(SequenceGeneratorInterface::class);
    }

    /**
     * Declares a sequence row. The model writes on the dedicated
     * sequences session, so the row is committed immediately — exactly
     * like production seeding.
     */
    private function declareSequence(string $scope, int $nextValue = 1): void
    {
        NumberingSequence::query()->create([
            'scope' => $scope,
            'next_value' => $nextValue,
        ]);
    }

    public function test_emits_consecutive_values_starting_at_the_declared_next_value(): void
    {
        $this->declareSequence('generator_probe', 1);

        $emitted = [
            $this->generator->next('generator_probe'),
            $this->generator->next('generator_probe'),
            $this->generator->next('generator_probe'),
        ];

        $this->assertSame([1, 2, 3], $emitted);
        $this->assertSame(
            4,
            (int) NumberingSequence::query()->where('scope', 'generator_probe')->value('next_value'),
        );
    }

    public function test_undeclared_scope_fails_loudly(): void
    {
        $this->expectException(UnknownSequenceException::class);
        $this->expectExceptionMessage('ghost_scope');

        $this->generator->next('ghost_scope');
    }

    public function test_emitted_numbers_survive_a_business_rollback(): void
    {
        $this->declareSequence('rollback_probe', 1);

        $burned = null;

        try {
            DB::transaction(function () use (&$burned): void {
                $burned = $this->generator->next('rollback_probe');

                // Business data fails AFTER the number was handed out.
                throw new RuntimeException('business data rejected');
            });
        } catch (RuntimeException) {
            // Expected: the whole business transaction rolls back.
        }

        $this->assertSame(1, $burned);

        // RN-009: the burned number is never handed out again — the
        // increment was committed on the dedicated sequences session,
        // not on the business transaction.
        $this->assertSame(2, $this->generator->next('rollback_probe'));
    }

    public function test_sequence_model_is_bound_to_the_dedicated_connection(): void
    {
        $model = new NumberingSequence;

        $this->assertSame(NumberingSequence::CONNECTION_NAME, $model->getConnectionName());
        $this->assertSame('sequences', $model->getConnectionName());
    }

    public function test_next_for_territory_emits_consecutive_values_and_persists_the_increment(): void
    {
        $emitted = [
            $this->generator->nextForTerritory('territorial_probe', 2026, '11', '03'),
            $this->generator->nextForTerritory('territorial_probe', 2026, '11', '03'),
            $this->generator->nextForTerritory('territorial_probe', 2026, '11', '03'),
        ];

        $this->assertSame([1, 2, 3], $emitted);
        $this->assertSame(
            4,
            (int) NumberingSequence::query()->where('scope', 'territorial_probe:2026:11:03')->value('next_value'),
        );
    }

    public function test_next_for_territory_births_a_new_territory_at_one(): void
    {
        // No seeded row: the first emission of a new territory creates
        // the scope row at 1 — neither the year nor the territorial
        // rollover needs an operator.
        $this->assertSame(1, $this->generator->nextForTerritory('fresh_territory_probe', 2031, '09', '01'));

        $this->assertSame(
            2,
            (int) NumberingSequence::query()->where('scope', 'fresh_territory_probe:2031:09:01')->value('next_value'),
        );
    }

    public function test_next_for_territory_keeps_one_consecutive_per_year_province_and_municipality(): void
    {
        $this->assertSame(1, $this->generator->nextForTerritory('multi_territory_probe', 2026, '11', '03'));
        $this->assertSame(2, $this->generator->nextForTerritory('multi_territory_probe', 2026, '11', '03'));

        // A different municipality restarts its own consecutive at 1...
        $this->assertSame(1, $this->generator->nextForTerritory('multi_territory_probe', 2026, '11', '04'));
        // ...a different province does too...
        $this->assertSame(1, $this->generator->nextForTerritory('multi_territory_probe', 2026, '15', '03'));
        // ...and so does a different year.
        $this->assertSame(1, $this->generator->nextForTerritory('multi_territory_probe', 2027, '11', '03'));

        // ...and the original territory is untouched by every rollover.
        $this->assertSame(3, $this->generator->nextForTerritory('multi_territory_probe', 2026, '11', '03'));
    }

    public function test_next_for_territory_does_not_disturb_the_declared_base_scope(): void
    {
        $this->declareSequence('base_territory_probe', 1);

        $this->assertSame(1, $this->generator->next('base_territory_probe'));
        $this->assertSame(1, $this->generator->nextForTerritory('base_territory_probe', 2026, '11', '03'));

        // The plain scope keeps its own consecutive: the territorial
        // scope is a different row (base_territory_probe:2026:11:03),
        // never the same one.
        $this->assertSame(2, $this->generator->next('base_territory_probe'));
    }

    public function test_territorial_emissions_survive_a_business_rollback(): void
    {
        $burned = null;

        try {
            DB::transaction(function () use (&$burned): void {
                $burned = $this->generator->nextForTerritory('territorial_rollback_probe', 2026, '11', '03');

                throw new RuntimeException('business data rejected');
            });
        } catch (RuntimeException) {
            // Expected: the business transaction rolls back.
        }

        $this->assertSame(1, $burned);
        $this->assertSame(2, $this->generator->nextForTerritory('territorial_rollback_probe', 2026, '11', '03'));
    }

    public function test_emit_command_prints_numbers_and_exits_successfully(): void
    {
        $this->declareSequence('command_probe', 100);

        $command = $this->artisan('sequences:emit', ['scope' => 'command_probe', '--times' => 3]);
        assert($command instanceof PendingCommand);

        $command->expectsOutput('100')
            ->expectsOutput('101')
            ->expectsOutput('102')
            ->assertExitCode(0);
    }

    public function test_emit_command_fails_cleanly_for_undeclared_scope(): void
    {
        $command = $this->artisan('sequences:emit', ['scope' => 'ghost_scope', '--times' => 1]);
        assert($command instanceof PendingCommand);

        $command->assertExitCode(1);
    }
}
