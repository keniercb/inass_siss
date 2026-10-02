<?php

declare(strict_types=1);

// Task 37 (SGP-31, corrección de usuario): revisión de los
// subregistros de servicio — el vínculo SIEMPRE está cerrado y es
// disjunto del resto:
//
//   1. end_date pasa a NOT NULL (la fecha de fin es OBLIGATORIA) y el
//      CHECK se endurece de «end >= start» a «end > start» — el fin
//      debe ser ESTRICTAMENTE posterior al inicio;
//   2. el solapamiento no se admite en la base de datos: MySQL no lo
//      expresa como constraint, pero con el fin obligatorio y los
//      períodos disjuntos la sonda del dominio (ServicePeriods) pasa
//      de advertencia a RECHAZO (422) en los DOS puntos de entrada
//      (payload anidado del alta y alta individual).
//
// ALTER directo en tres pasos deterministas (drop CHECK → MODIFY →
// nuevo CHECK): la corrección asume que NINGUNA fila almacenada
// mantiene un vínculo abierto o un solapamiento — etapa de desarrollo
// (el CI corre migrate:fresh); un despliegue real debe cerrar sus
// vínculos abiertos antes de migrar. El down es reversible.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1) The old order rule leaves first…
        DB::statement('ALTER TABLE service_records DROP CONSTRAINT chk_service_records_dates');

        // 2) …the end date becomes mandatory…
        DB::statement('ALTER TABLE service_records MODIFY end_date DATE NOT NULL');

        // 3) …and the new rule lands: strictly posterior ends.
        DB::statement(
            'ALTER TABLE service_records ADD CONSTRAINT chk_service_records_dates'
            .' CHECK (end_date > start_date)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE service_records DROP CONSTRAINT chk_service_records_dates');

        DB::statement('ALTER TABLE service_records MODIFY end_date DATE NULL');

        // Sprint 5 shape: optional end (NULL = vínculo vigente) with
        // the weak end >= start order rule.
        DB::statement(
            'ALTER TABLE service_records ADD CONSTRAINT chk_service_records_dates'
            .' CHECK (end_date IS NULL OR end_date >= start_date)'
        );
    }
};
