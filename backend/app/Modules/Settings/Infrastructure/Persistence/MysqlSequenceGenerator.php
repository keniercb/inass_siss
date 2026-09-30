<?php

declare(strict_types=1);

namespace App\Modules\Settings\Infrastructure\Persistence;

use App\Modules\Settings\Infrastructure\Persistence\Models\NumberingSequence;
use App\Modules\Shared\Contracts\SequenceGeneratorInterface;
use App\Modules\Shared\Exceptions\UnknownSequenceException;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * MySQL adapter of the Shared sequence port (RF-PAG-006, RN-009,
 * ADR-17 — Adapter pattern: "the domain asks for the next number,
 * the infrastructure resolves it with pessimistic locking").
 *
 * Emission protocol, inside one transaction on the dedicated
 * "sequences" session:
 *
 *  1. SELECT ... FOR UPDATE locks the scope row — parallel emitters
 *     serialize here, so two transactions can never read the same
 *     next_value (no duplicates under real contention).
 *  2. The read value is handed out and next_value is persisted as
 *     value + 1.
 *  3. COMMIT releases the lock.
 *
 * The transaction runs on a session that is never the caller's
 * business transaction: the increment is durable even when business
 * data rolls back afterwards, so an emitted number is burned forever
 * (RN-009 — never reused, gaps accepted by design). Single-row
 * locking makes emission deadlocks structurally impossible: every
 * emission transaction locks exactly one row, so no circular wait
 * can form.
 */
final class MysqlSequenceGenerator implements SequenceGeneratorInterface
{
    public function __construct(private readonly ConnectionResolverInterface $connections) {}

    public function next(string $sequenceName): int
    {
        $connection = $this->connections->connection(NumberingSequence::CONNECTION_NAME);

        // The closure returns int; Connection::transaction() is typed
        // mixed by the framework, hence the cast below.
        $value = $connection->transaction(function () use ($sequenceName): int {
            $row = NumberingSequence::query()
                ->where('scope', $sequenceName)
                ->lockForUpdate()
                ->first();

            if (! $row instanceof NumberingSequence) {
                throw UnknownSequenceException::forScope($sequenceName);
            }

            $current = (int) $row->next_value;
            $row->next_value = $current + 1;
            $row->save();

            return $current;
        });

        return (int) $value;
    }

    public function nextForTerritory(string $baseScope, int $year, string $provinceCode, string $municipalityCode): int
    {
        $scope = $baseScope.':'.$year.':'.$provinceCode.':'.$municipalityCode;

        $connection = $this->connections->connection(NumberingSequence::CONNECTION_NAME);

        $value = $connection->transaction(function () use ($scope, $connection): int {
            // A territory without a row is BORN at 1 (ADR-34): the
            // insert is idempotent (INSERT IGNORE), so two concurrent
            // first-emitters of a new territory converge on a single
            // row instead of racing an UnknownSequenceException —
            // neither the year nor the territorial rollover needs an
            // operator.
            $connection->table((new NumberingSequence)->getTable())
                ->insertOrIgnore([
                    'scope' => $scope,
                    'next_value' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            // Same pessimistic protocol as next(): the row is locked,
            // the read value is handed out and the increment is
            // committed on this dedicated session, independent of the
            // caller's business transaction.
            $row = NumberingSequence::query()
                ->where('scope', $scope)
                ->lockForUpdate()
                ->first();

            if (! $row instanceof NumberingSequence) {
                // Unreachable under the insert above; kept so the
                // type of $row is provably non-null for the static
                // analyzer and any future storage swap.
                throw UnknownSequenceException::forScope($scope);
            }

            $current = (int) $row->next_value;
            $row->next_value = $current + 1;
            $row->save();

            return $current;
        });

        return (int) $value;
    }
}
