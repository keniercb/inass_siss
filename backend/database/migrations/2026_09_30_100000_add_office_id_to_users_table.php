<?php

declare(strict_types=1);

// Modelos de datos 5.4: users.office_id — Pertenencia del usuario a
// una oficina territorial (ADR-29). Nullable: las cuentas centrales
// (admin/auditor) operan sin oficina y el campo se asigna a través de
// la gestión de cuentas; restrict: una oficina con usuarios activos
// no puede eliminarse físicamente (la desactivación además se niega
// conversacionalmente desde el servicio mientras queden usuarios
// activos asignados — nadie pierde su oficina en silencio).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('office_id')
                ->nullable()
                ->after('person_id')
                ->constrained('offices')
                ->restrictOnDelete();

            $table->index('office_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['office_id']);
            $table->dropIndex(['office_id']);
            $table->dropColumn('office_id');
        });
    }
};
