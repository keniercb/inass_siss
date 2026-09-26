<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

/**
 * Transaction boundary abstraction.
 *
 * Use cases that must persist several aggregates atomically (e.g. approving a
 * case creates the pensioner in the same transaction, RF-PEN-001) receive this
 * contract instead of calling the framework directly. This keeps the
 * Application layer framework-agnostic and lets unit tests replace the
 * implementation with an in-memory fake.
 */
interface TransactionManager
{
    /**
     * Executes the given operation inside a single database transaction.
     *
     * The transaction is committed when the operation returns normally and
     * rolled back when it throws. The operation's return value is forwarded
     * to the caller as-is.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public function execute(callable $operation): mixed;
}
