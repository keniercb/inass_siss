<?php

declare(strict_types=1);

namespace App\Modules\Settings\Tests\Feature;

use App\Modules\Settings\Infrastructure\Persistence\Models\NumberingSequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real multi-process concurrency proof for the centralized sequences
 * (plan S2.5, RN-009): eight parallel PHP processes race the same
 * sequence through the pessimistic-lock adapter (SELECT ... FOR UPDATE
 * inside the emission transaction). The union of the emitted values
 * must be duplicate-free and gap-free, and the persisted next_value
 * must point past the last emitted number. Wall time is reported to
 * STDERR as the contention evidence attached to the delivery PR.
 */
final class SequenceConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const PROCESSES = 8;

    private const EMISSIONS_PER_PROCESS = 5;

    public function test_parallel_processes_emit_without_gaps_or_duplicates(): void
    {
        $scope = 'concurrency_probe_'.bin2hex(random_bytes(4));

        // Declared on the dedicated sequences session: committed
        // immediately, so every child process can see it.
        NumberingSequence::query()->create([
            'scope' => $scope,
            'next_value' => 1,
        ]);

        $processes = [];
        foreach (range(1, self::PROCESSES) as $probe) {
            $processes[] = new Process(
                [
                    PHP_BINARY,
                    'artisan',
                    'sequences:emit',
                    $scope,
                    '--times='.(string) self::EMISSIONS_PER_PROCESS,
                    '--env=testing',
                    '--no-ansi',
                ],
                base_path(),
                null,
                null,
                300.0,
            );
        }

        $startedAt = microtime(true);

        // Start every probe first: this is what creates real contention.
        foreach ($processes as $process) {
            $process->start();
        }

        foreach ($processes as $process) {
            $process->wait();
        }

        $elapsedSeconds = microtime(true) - $startedAt;

        $numbers = [];
        foreach ($processes as $index => $process) {
            $exitCode = $process->getExitCode();
            $this->assertSame(
                0,
                $exitCode,
                "probe {$index} exited with {$exitCode}:\n{$process->getErrorOutput()}",
            );

            $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
            $this->assertCount(
                self::EMISSIONS_PER_PROCESS,
                $lines,
                "probe {$index} printed ".count($lines).' numbers instead: '.PHP_EOL.$process->getOutput(),
            );

            foreach ($lines as $line) {
                $numbers[] = (int) $line;
            }
        }

        $expected = range(1, self::PROCESSES * self::EMISSIONS_PER_PROCESS);
        sort($numbers);

        $this->assertCount(self::PROCESSES * self::EMISSIONS_PER_PROCESS, $numbers);
        $this->assertSame(
            $expected,
            $numbers,
            'emitted values must be consecutive without duplicates or gaps (RN-009)',
        );

        // Fresh session: the parent may hold a stale snapshot otherwise.
        DB::purge(NumberingSequence::CONNECTION_NAME);

        $this->assertSame(
            self::PROCESSES * self::EMISSIONS_PER_PROCESS + 1,
            (int) NumberingSequence::query()->where('scope', $scope)->value('next_value'),
            'the persisted next_value must point past the last emitted number',
        );

        fwrite(STDERR, sprintf(
            ' [RN-009 evidence] %d processes x %d emissions = %d unique values in %.2fs%s',
            self::PROCESSES,
            self::EMISSIONS_PER_PROCESS,
            self::PROCESSES * self::EMISSIONS_PER_PROCESS,
            $elapsedSeconds,
            PHP_EOL,
        ));
    }
}
