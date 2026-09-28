<?php

declare(strict_types=1);

// Modelos de datos 5.7: service_records — Historial laboral del
// expediente (RF-EXP-003, H-15). Cada servicio declara entidad,
// inicio, fin opcional (NULL = vínculo vigente) y el marcador de
// coletilla; el orden fechas end >= start queda respaldado por CHECK
// (RN-006) y los solapamientos — que MySQL no expresa como constraint
// — los detecta y advierte el dominio (ServicePeriods). Sin soft
// delete: la baja es física y la bitácora conserva los valores
// previos (ADR-19).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pension_case_id')->constrained('pension_cases')->restrictOnDelete();
            $table->foreignId('entity_id')->constrained('entities')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            // Coletilla: servicio reconocido adicional (RF-EXP-003).
            $table->boolean('is_appendix')->default(false);

            $table->index(['pension_case_id', 'start_date'], 'idx_service_records_case_start');
        });

        // RN-006: el fin del vínculo nunca precede a su inicio.
        DB::statement(
            'ALTER TABLE service_records ADD CONSTRAINT chk_service_records_dates'
            .' CHECK (end_date IS NULL OR end_date >= start_date)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('service_records');
    }
};
