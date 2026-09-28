<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use App\Modules\Shared\Contracts\TransactionManager;
use Illuminate\Database\DatabaseManager;

/**
 * Eloquent-backed implementation of the transaction boundary
 * (RF-PEN-001's "same transaction" rule and the S5.5 all-or-nothing
 * case creation). Lives in Shared so every module can depend on the
 * port without acquiring a new cross-module edge (deptrac: Shared
 * depends on nothing).
 */
final class DatabaseTransactionManager implements TransactionManager
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function execute(callable $operation): mixed
    {
        return $this->db->connection()->transaction(
            static fn (): mixed => $operation(),
        );
    }
}
