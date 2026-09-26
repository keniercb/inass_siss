<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

/**
 * Centralized, concurrency-safe sequence generator (RN-009).
 *
 * Every natural number exposed by the system (case numbers, bank control
 * numbers) comes from a named sequence owned by this contract. The MySQL
 * implementation (phase 1, Settings module) must use pessimistic locking so
 * that parallel transactions never produce duplicated or missing values.
 * Sequence values are never reused, even after rollbacks of business data.
 */
interface SequenceGeneratorInterface
{
    /**
     * Returns the next value of the named sequence.
     *
     * @param  non-empty-string  $sequenceName
     */
    public function next(string $sequenceName): int;
}
