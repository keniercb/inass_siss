<?php

declare(strict_types=1);

// Gestión de roles (RF-SEG-002, ADR-26): columnas de gestión sobre
// la tabla roles de spatie/laravel-permission. description documenta
// el propósito del rol para el directorio; is_system marca los cinco
// roles institucionales de la sección 2.2 sembrados desde la
// PermissionMatrix — inmutables vía API porque la matriz es su única
// fuente de verdad — y distingue los roles personalizados que la
// superficie de gestión crea con subconjuntos del catálogo. El
// backfill marca los institucionales ya sembrados; el seeder además
// fija la descripción de cada uno.

use App\Modules\Security\Domain\Authorization\PermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->string('description', 255)->nullable()->after('guard_name');
            $table->boolean('is_system')->default(false)->after('description');
        });

        foreach (PermissionMatrix::roles() as $institutional) {
            DB::table('roles')
                ->where('name', $institutional)
                ->where('guard_name', 'web')
                ->update(['is_system' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn(['description', 'is_system']);
        });
    }
};
