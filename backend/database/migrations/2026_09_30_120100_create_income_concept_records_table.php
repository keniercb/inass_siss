<?php

declare(strict_types=1);

// User rule 5: income_concept_records — the declared income concepts
// of the expediente. Each row states ONE VALUE for ONE concept of
// the catálogo income_concepts (salario en divisas, antigüedad…):
// the (case, concept) pair is UNIQUE — declaring the same concept
// twice would be two values for the same fact —, the value is
// DECIMAL(12,2) non negative (RN-005: exact money, never floating
// point) and the rows follow the aggregate's subrecord conventions
// (no soft delete, no authorship columns: the expediente owns the
// authorship and the append-only bitácora keeps the previous values
// of every high/removal, ADR-19).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_concept_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pension_case_id')->constrained('pension_cases')->restrictOnDelete();
            $table->foreignId('income_concept_id')->constrained('income_concepts')->restrictOnDelete();
            // RN-005: money is DECIMAL(12,2), never floating point.
            $table->decimal('amount', 12, 2);

            // One declared value per (case, concept) pair.
            $table->unique(['pension_case_id', 'income_concept_id'], 'uq_income_concept_records_case_concept');
        });

        // RN-005: the declared value is never negative.
        DB::statement(
            'ALTER TABLE income_concept_records ADD CONSTRAINT chk_income_concept_records_amount'
            .' CHECK (amount >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('income_concept_records');
    }
};
