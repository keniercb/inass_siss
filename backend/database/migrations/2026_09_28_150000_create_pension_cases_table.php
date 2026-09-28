<?php

declare(strict_types=1);

// Modelos de datos 5.7: pension_cases — Expediente de pensión, agregado
// raíz del módulo (RF-EXP-001, corrección H-02 del análisis). El número
// proviene de la secuencia centralizada `pension_case` (RN-009/ADR-17);
// el estado inicial es `submitted` y su catálogo de valores queda
// respaldado por CHECK (la máquina de transiciones llega en S6 como
// dataset); el importe `last_salary` es DECIMAL(12,2) no negativo
// (RN-005) y la unicidad de «un expediente abierto por persona» se
// materializa con la columna generada `open_case_key` — NULL cuando el
// expediente alcanzó un estado terminal, de modo que el UNIQUE admite
// tantas filas terminales como haga falta pero a lo sumo una viva por
// proponente.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pension_cases', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 20)->unique();
            $table->date('requested_at');
            $table->string('status', 20);
            $table->foreignId('applicant_person_id')->constrained('people')->restrictOnDelete();
            $table->foreignId('office_id')->constrained('offices')->restrictOnDelete();
            $table->foreignId('employer_entity_id')->constrained('entities')->restrictOnDelete();
            $table->foreignId('position_id')->constrained('positions')->restrictOnDelete();
            $table->foreignId('occupational_category_id')->constrained('occupational_categories')->restrictOnDelete();
            $table->foreignId('educational_level_id')->constrained('educational_levels')->restrictOnDelete();
            $table->foreignId('scientific_category_id')->constrained('scientific_categories')->restrictOnDelete();
            // RN-005: money is DECIMAL(12,2), never floating point.
            $table->decimal('last_salary', 12, 2);
            // H-05: the approving resolution lands here (obligatory at
            // approval, unused until the S6 transition).
            $table->foreignId('approval_legal_basis_id')->nullable()->constrained('legal_bases')->restrictOnDelete();
            $table->text('decision_notes')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->decimal('computed_amount', 12, 2)->nullable();
            $table->foreignId('calculation_setting_id')->nullable()->constrained('general_settings')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // One OPEN case per person (plan S5.1): the stored generated
            // column is NULL on terminal states, so the UNIQUE admits
            // unlimited terminal rows while keeping at most one live
            // case per applicant. Soft-deleted rows keep the key — the
            // reservation mirrors RN-001 — but no public endpoint
            // deletes cases, so the reserve only guards manual writes.
            $table->unsignedBigInteger('open_case_key')
                ->nullable()
                ->storedAs("IF(status IN ('approved', 'rejected'), NULL, applicant_person_id)");
            $table->unique('open_case_key', 'uq_pension_cases_open_per_person');

            // idx_cases_office_status: gestión por oficina y estado.
            $table->index(['office_id', 'status', 'requested_at'], 'idx_cases_office_status');
            $table->index(['applicant_person_id', 'status'], 'idx_cases_person_status');
        });

        // Catálogo normativo de estados (sección 2.4). La matriz de
        // transiciones se decide en el dominio (S6) — el CHECK fija la
        // frontera de valores legales en la base.
        DB::statement(
            'ALTER TABLE pension_cases ADD CONSTRAINT chk_pension_cases_status'
            ." CHECK (status IN ('submitted', 'under_review', 'approved', 'rejected'))"
        );
        // RN-005: el último salario jamás es negativo.
        DB::statement(
            'ALTER TABLE pension_cases ADD CONSTRAINT chk_pension_cases_last_salary'
            .' CHECK (last_salary >= 0)'
        );
        DB::statement(
            'ALTER TABLE pension_cases ADD CONSTRAINT chk_pension_cases_computed_amount'
            .' CHECK (computed_amount IS NULL OR computed_amount >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('pension_cases');
    }
};
