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
