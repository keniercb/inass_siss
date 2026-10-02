<?php

declare(strict_types=1);

// Task 36 (SGP-30, corrección de usuario): las columnas añadidas por
// las correcciones Task 32-35 en español pasan al patrón INGLÉS de
// todas las columnas previas del esquema (ADR-03, vinculante para
// todo el desarrollo). service_records.forma_declaracion se renombra
// a declaration_form — espejo del enum de dominio
// ServiceDeclarationForm — conservando el tipo VARCHAR(20) NOT NULL
// DEFAULT 'Documental'. El CHECK se recrea con nombre inglés porque
// MySQL no renombra constraints: se suelta el viejo antes del rename
// y se re-añade tras él con la misma pareja de valores legales
// (Documental|Testifical, valores de dominio definidos por el
// usuario en la Task 32 — no cambian).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE service_records DROP CONSTRAINT chk_service_records_forma_declaracion'
        );

        Schema::table('service_records', function (Blueprint $table): void {
            $table->renameColumn('forma_declaracion', 'declaration_form');
        });

        DB::statement(
            'ALTER TABLE service_records ADD CONSTRAINT chk_service_records_declaration_form'
            ." CHECK (declaration_form IN ('Documental', 'Testifical'))"
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE service_records DROP CONSTRAINT chk_service_records_declaration_form'
        );

        Schema::table('service_records', function (Blueprint $table): void {
            $table->renameColumn('declaration_form', 'forma_declaracion');
        });

        DB::statement(
            'ALTER TABLE service_records ADD CONSTRAINT chk_service_records_forma_declaracion'
            ." CHECK (forma_declaracion IN ('Documental', 'Testifical'))"
        );
    }
};
