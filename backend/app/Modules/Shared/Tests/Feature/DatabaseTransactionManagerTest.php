<?php

declare(strict_types=1);

// DatabaseTransactionManager wiring (S5.5/ADR-25): the Shared kernel
// now resolves the TransactionManager port. The gate measures this
// glue here instead of excluding it — the commit/rollback semantics
// are verified against the real MySQL of the suite (ADR-08), while
// the PensionCases feature suites cover the end-to-end consumers.

use App\Modules\Catalogs\Infrastructure\Persistence\Models\Province;
use App\Modules\Shared\Contracts\TransactionManager;
use App\Modules\Shared\Support\DatabaseTransactionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('binds the transaction port to the database implementation', function () {
    expect($this->app->make(TransactionManager::class))->toBeInstanceOf(DatabaseTransactionManager::class);
});

it('commits the operation and forwards its return value', function () {
    $transactions = $this->app->make(TransactionManager::class);

    $result = $transactions->execute(function (): string {
        Province::query()->create(['code' => 'T1', 'name' => 'Commit']);

        return 'committed';
    });

    expect($result)->toBe('committed')
        ->and(Province::query()->where('code', 'T1')->exists())->toBeTrue();
});

it('rolls everything back when the operation throws', function () {
    $transactions = $this->app->make(TransactionManager::class);

    try {
        $transactions->execute(function (): never {
            Province::query()->create(['code' => 'T2', 'name' => 'Rollback']);

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // Expected: the boundary rethrows after rolling back.
    }

    expect(Province::query()->where('code', 'T2')->exists())->toBeFalse();
});
