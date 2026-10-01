<?php

declare(strict_types=1);

// Modelos de datos 5.7: pension_cases — persona por (RF-EXP-001,
// corrección de usuario, Task 34): persona que presenta o gestiona el
// expediente cuando no es el propio proponente (un familiar, un
// apoderado, un gestor). Columna VARCHAR(120) NULL — texto libre
// opcional de paso (puro passthrough del wire): la omisión persiste
// NULL y ninguna regla semántica la exige, porque el caso puede
// presentarlo el propio solicitante. Sin CHECK: no es un enum sino
// un dato denominativo, como el nombre de la entidad (Task 31).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pension_cases', function (Blueprint $table): void {
            $table->string('persona_por', 120)->nullable()->after('rebel_army_join_date');
        });
    }

    public function down(): void
    {
        Schema::table('pension_cases', function (Blueprint $table): void {
            $table->dropColumn('persona_por');
        });
    }
};
