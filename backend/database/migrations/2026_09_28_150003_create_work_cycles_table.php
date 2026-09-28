<?php

declare(strict_types=1);

// Modelos de datos 5.7: work_cycles — Ciclos de trabajo del
// expediente (RF-EXP-004). Días plan, días reales y cantidad de
// ciclos: enteros no negativos (UNSIGNED como última línea) que el
// cómputo de años de servicio consumirá según el régimen (RF-CAL-002,
// Fase 4). Sin soft delete: la baja es física y auditada.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_cycles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pension_case_id')->constrained('pension_cases')->restrictOnDelete();
            $table->unsignedInteger('planned_days');
            $table->unsignedInteger('actual_days');
            $table->unsignedInteger('cycles_count');

            $table->index(['pension_case_id'], 'idx_work_cycles_case');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_cycles');
    }
};
