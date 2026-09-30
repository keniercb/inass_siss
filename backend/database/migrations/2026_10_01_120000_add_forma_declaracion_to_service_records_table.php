<?php

declare(strict_types=1);

// Modelos de datos 5.7: service_records — forma de declaración del
// vínculo laboral (RF-EXP-003, corrección de usuario, Task 32):
// Documental (respaldo documental, valor por defecto) o Testifical
// (declaración testimonial). La columna llega NOT NULL con DEFAULT
// 'Documental': las filas existentes heredan el valor del DEFAULT
// sin backfill y toda fila nueva que no la declare cae en Documental
// (regla de usuario). El par de valores legales queda respaldado por
// CHECK, espejo del RN-006 de fechas.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_records', function (Blueprint $table): void {
            $table->string('forma_declaracion', 20)->default('Documental')->after('is_appendix');
        });

        // La forma de declaración siempre es una de las dos legales.
        DB::statement(
            'ALTER TABLE service_records ADD CONSTRAINT chk_service_records_forma_declaracion'
            ." CHECK (forma_declaracion IN ('Documental', 'Testifical'))"
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE service_records DROP CONSTRAINT chk_service_records_forma_declaracion'
        );

        Schema::table('service_records', function (Blueprint $table): void {
            $table->dropColumn('forma_declaracion');
        });
    }
};
