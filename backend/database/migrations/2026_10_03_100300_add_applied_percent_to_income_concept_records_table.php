<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 42 (user correction, SGP-36): every declared income concept
 * gains the percent to apply — applied_percent, a DECIMAL(5,2) with
 * range 0-100. The user asked for a Double: the project's RN-005
 * doctrine keeps every exact numeric as a decimal column (never a
 * binary float), so the wire travels an exact decimal string with at
 * most two decimals and the CHECK backs the range as the last
 * deterministic line. REQUIRED at both write paths (the nested rows
 * of the atomic creation and the individual endpoint answer 422 when
 * it is omitted, out of range or carrying a third decimal); the
 * DEFAULT 0.00 only covers writes outside the wire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('income_concept_records', function (Blueprint $table): void {
            $table->decimal('applied_percent', 5, 2)->default('0.00')->after('amount');
        });

        DB::statement(
            'ALTER TABLE income_concept_records ADD CONSTRAINT chk_income_concept_records_applied_percent'
            .' CHECK (applied_percent >= 0 AND applied_percent <= 100)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE income_concept_records DROP CONSTRAINT chk_income_concept_records_applied_percent');

        Schema::table('income_concept_records', function (Blueprint $table): void {
            $table->dropColumn('applied_percent');
        });
    }
};
