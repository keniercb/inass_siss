<?php

declare(strict_types=1);

// Modelos de datos 5.7: pension_cases — persona por como REFERENCIA
// (corrección de usuario sobre la Task 34, Task 35 / SGP-29): la
// persona por no es texto libre sino una persona REGISTRADA del
// registro de People. La migración sustituye la columna
// persona_por VARCHAR(120) NULL de la Task 34 por
// persona_por_id BIGINT UNSIGNED NULL con FK a people
// (restrictOnDelete, como el resto de las referencias de la tabla:
// el registro nunca borra en cascada historia de expedientes). El
// sistema está en etapa de desarrollo sin datos productivos de la
// columna — la sustitución directa es el historial limpio.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pension_cases', function (Blueprint $table): void {
            $table->dropColumn('persona_por');
        });

        Schema::table('pension_cases', function (Blueprint $table): void {
            $table->foreignId('persona_por_id')
                ->nullable()
                ->after('rebel_army_join_date')
                ->constrained('people')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pension_cases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('persona_por_id');
        });

        Schema::table('pension_cases', function (Blueprint $table): void {
            $table->string('persona_por', 120)->nullable()->after('rebel_army_join_date');
        });
    }
};
