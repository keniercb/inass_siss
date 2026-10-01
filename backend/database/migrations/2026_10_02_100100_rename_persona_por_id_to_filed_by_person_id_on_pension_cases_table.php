<?php

declare(strict_types=1);

// Task 36 (SGP-30, corrección de usuario): pension_cases.persona_por_id
// se renombra a filed_by_person_id — patrón inglés de todas las
// columnas previas (ADR-03, paralelo de applicant_person_id: la
// persona REGISTRADA que presenta o gestiona el expediente cuando no
// es el propio promovente). El renombrado es puro metadato: FK a
// people con restrictOnDelete y NULL opcional se conservan. La FK se
// suelta ANTES del rename y se re-añade DESPUÉS con nombre inglés
// porque MySQL no renombra constraints — la secuencia drop/rename/
// re-add es la vía determinista sin depender de reescrituras
// automáticas del motor.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pension_cases', function (Blueprint $table): void {
            $table->dropForeign(['persona_por_id']);
        });

        Schema::table('pension_cases', function (Blueprint $table): void {
            $table->renameColumn('persona_por_id', 'filed_by_person_id');
        });

        Schema::table('pension_cases', function (Blueprint $table): void {
            $table->foreign('filed_by_person_id')
                ->references('id')
                ->on('people')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pension_cases', function (Blueprint $table): void {
            $table->dropForeign(['filed_by_person_id']);
        });

        Schema::table('pension_cases', function (Blueprint $table): void {
            $table->renameColumn('filed_by_person_id', 'persona_por_id');
        });

        Schema::table('pension_cases', function (Blueprint $table): void {
            $table->foreign('persona_por_id')
                ->references('id')
                ->on('people')
                ->restrictOnDelete();
        });
    }
};
