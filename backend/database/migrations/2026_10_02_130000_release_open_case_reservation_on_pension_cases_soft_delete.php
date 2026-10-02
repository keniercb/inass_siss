<?php

declare(strict_types=1);

// SGP-34 (user correction): DELETE /pension-cases/{id} soft-deletes a
// SUBMITTED case — and the soft delete must RELEASE the one-open-case
// reservation (plan S5.1) so the operator can re-capture the applicant
// after eliminating a mistaken registration. The original generated
// column kept the key on soft-deleted rows because «no public endpoint
// deletes cases, so the reserve only guards manual writes» — that
// premise changed: the endpoint exists now, and keeping the reserve
// would turn every re-capture into a driver-level UNIQUE violation
// (500) after the service-level probe already green-lit the insert.
//
// New expression: open_case_key is NULL on terminal states AND on
// soft-deleted rows — the UNIQUE still guarantees at most one LIVE
// case per applicant, exactly what findOpenCaseForPerson (which
// already excludes soft-deleted rows through the SoftDeletes scope)
// probes semantically.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL refuses DDL over an indexed stored generated column:
        // the index leaves first, then the column, then both return
        // with the widened expression.
        DB::statement('ALTER TABLE pension_cases DROP INDEX uq_pension_cases_open_per_person');
        DB::statement('ALTER TABLE pension_cases DROP COLUMN open_case_key');
        DB::statement(
            'ALTER TABLE pension_cases'
            ." ADD COLUMN open_case_key BIGINT UNSIGNED NULL"
            ." GENERATED ALWAYS AS (IF(status IN ('approved', 'rejected') OR deleted_at IS NOT NULL, NULL, applicant_person_id)) STORED"
        );
        DB::statement(
            'ALTER TABLE pension_cases'
            .' ADD UNIQUE INDEX uq_pension_cases_open_per_person (open_case_key)'
        );
    }

    public function down(): void
    {
        // Back to the Sprint 5 shape: the reservation ignores the
        // soft-delete flag (no public delete existed then).
        DB::statement('ALTER TABLE pension_cases DROP INDEX uq_pension_cases_open_per_person');
        DB::statement('ALTER TABLE pension_cases DROP COLUMN open_case_key');
        DB::statement(
            'ALTER TABLE pension_cases'
            ." ADD COLUMN open_case_key BIGINT UNSIGNED NULL"
            ." GENERATED ALWAYS AS (IF(status IN ('approved', 'rejected'), NULL, applicant_person_id)) STORED"
        );
        DB::statement(
            'ALTER TABLE pension_cases'
            .' ADD UNIQUE INDEX uq_pension_cases_open_per_person (open_case_key)'
        );
    }
};
