<?php

declare(strict_types=1);

namespace App\Modules\Shared\Tests\Feature;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\Province;
use App\Modules\Shared\Contracts\TransactionManager;
use App\Modules\Shared\Support\DatabaseTransactionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * DatabaseTransactionManager wiring (S5.5/ADR-25): the Shared kernel
 * now resolves the TransactionManager port, and the coverage gate
 * measures this glue here instead of excluding it — the
 * commit/rollback semantics run against the real MySQL of the suite
 * (ADR-08) while the PensionCases feature suites cover the
 * end-to-end consumers.
 */
final class DatabaseTransactionManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_the_port_to_the_database_implementation(): void
    {
        $transactions = $this->app->make(TransactionManager::class);

        $this->assertInstanceOf(DatabaseTransactionManager::class, $transactions);
    }

    public function test_commits_the_operation_and_forwards_its_return_value(): void
    {
        $transactions = $this->app->make(TransactionManager::class);

        $result = $transactions->execute(function (): string {
            Province::query()->create(['code' => 'T1', 'name' => 'Commit']);

            return 'committed';
        });

        $this->assertSame('committed', $result);
        $this->assertTrue(Province::query()->where('code', 'T1')->exists());
    }

    public function test_rolls_everything_back_when_the_operation_throws(): void
    {
        $transactions = $this->app->make(TransactionManager::class);

        try {
            $transactions->execute(function (): never {
                Province::query()->create(['code' => 'T2', 'name' => 'Rollback']);

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // Expected: the boundary rethrows after rolling back. If
            // it swallowed the failure instead, the row would survive
            // and the assertion below would fail.
        }

        $this->assertFalse(Province::query()->where('code', 'T2')->exists());
    }
}
