<?php

declare(strict_types=1);

// Task 37 (SGP-31, corrección de usuario): el expediente lleva el par
// de contacto del promovente y la marca de internacionalista. Columnas
// en INGLÉS conforme al patrón vinculante ADR-03 fijado en la Task 36:
//
//   - internationalist TINYINT(1) NOT NULL DEFAULT 0 — marca de
//     internacionalista del promovente, paralelo del par de Ejército
//     Rebelde (obligatoria en el wire como rebel_army_member; el
//     DEFAULT solo cubre escrituras fuera del wire);
//   - phone VARCHAR(30) NULL — teléfono de contacto del promovente;
//   - popular_council VARCHAR(120) NULL — consejo popular (división
//     territorial cubana) del promovente.
//
// Texto libre opcional de contacto, como el nombre de la entidad: sin
// CHECK ni FK — no hay catálogo de consejos populares.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pension_cases', function (Blueprint $table): void {
            $table->boolean('internationalist')
                ->default(false)
                ->after('rebel_army_join_date');
            $table->string('phone', 30)
                ->nullable()
                ->after('filed_by_person_id');
            $table->string('popular_council', 120)
                ->nullable()
                ->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('pension_cases', function (Blueprint $table): void {
            $table->dropColumn(['internationalist', 'phone', 'popular_council']);
        });
    }
};
