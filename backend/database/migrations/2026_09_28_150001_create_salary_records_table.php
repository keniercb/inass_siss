<?php

declare(strict_types=1);

// Modelos de datos 5.7: salary_records — Serie salarial anual del
// expediente (RF-EXP-002, RN-005). El par expediente-año es UNIQUE
// (sondeado semánticamente antes del insert, RN-008), el año respeta
// 1950 como piso normativo — el techo «año actual+1» se valida contra
// el reloj de dominio porque NOW() no cabe en un CHECK determinista —
// y el importe es DECIMAL(12,2) no negativo. Sin soft delete ni
// autoría propia: el expediente es el agregado y la bitácora registra
// las altas y bajas con sus valores.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pension_case_id')->constrained('pension_cases')->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('earned_salary', 12, 2);

            $table->unique(['pension_case_id', 'year'], 'uq_salary_records_case_year');
        });

        // RF-EXP-002: piso normativo del año. El techo (año actual+1)
        // lo decide el dominio contra ClockInterface.
        DB::statement(
            'ALTER TABLE salary_records ADD CONSTRAINT chk_salary_records_year'
            .' CHECK (year >= 1950)'
        );
        // RN-005: el salario devengado jamás es negativo.
        DB::statement(
            'ALTER TABLE salary_records ADD CONSTRAINT chk_salary_records_earned'
            .' CHECK (earned_salary >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_records');
    }
};
