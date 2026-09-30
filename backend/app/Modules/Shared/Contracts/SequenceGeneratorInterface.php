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

    /**
     * Returns the next value of the TERRITORIAL variant of the named
     * sequence (ADR-34: case numbers PPMMAACCCCC consume one
     * consecutive PER YEAR, PROVINCE AND MUNICIPALITY).
     *
     * The scope row is "{base}:{year}:{province}:{municipality}":
     * unlike next() — where scopes must be declared up front and an
     * undeclared one fails loudly — a territory that has no row yet
     * is BORN at 1 inside the same pessimistic-lock transaction, so
     * neither the year nor the territorial rollover needs an
     * operator and never hands out a duplicate. Each territory keeps
     * its own consecutive; the base scope is never disturbed.
     *
     * @param  non-empty-string  $baseScope
     * @param  string  $provinceCode  two digits of the ONEI province catalog
     * @param  string  $municipalityCode  two digits of the ONEI municipality catalog
     */
    public function nextForTerritory(string $baseScope, int $year, string $provinceCode, string $municipalityCode): int;
}
