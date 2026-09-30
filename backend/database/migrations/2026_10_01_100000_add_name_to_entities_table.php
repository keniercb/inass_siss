<?php

declare(strict_types=1);

// Modelos de datos 5.5: entities — nombre de la entidad (corrección de
// usuario, Task 31). La columna viaja DESPUÉS del código y es NOT NULL:
// toda entidad empleadora declara su nombre denominativo junto al
// código único y el NIT. Las filas preexistentes (bases de desarrollo)
// se rellenan con su propio código antes de endurecer la columna, así
// la migración es segura sobre bases con datos.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table): void {
            $table->string('name', 120)->nullable()->after('code');
        });

        // Backfill: el nombre de las filas existentes cae en su código
        // (identidad denominativa mínima y determinista).
        DB::table('entities')
            ->whereNull('name')
            ->update(['name' => DB::raw('`code`')]);

        Schema::table('entities', function (Blueprint $table): void {
            $table->string('name', 120)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table): void {
            $table->dropColumn('name');
        });
    }
};
