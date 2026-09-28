<?php

declare(strict_types=1);

// Modelos de datos 5.5: offices — Oficinas del Ministerio (RF-ENT-002).
// Same RN-04 double mechanism as entities; the hierarchy shares the
// domain HierarchyPolicy (RN-003). No natural key: offices are
// identified by their geographic scope and type.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_type_id')->constrained('office_types')->restrictOnDelete();
            $table->foreignId('province_id')->constrained('provinces')->restrictOnDelete();
            $table->foreignId('municipality_id')->constrained('municipalities')->restrictOnDelete();
            // RN-04: the municipality must belong to the declared province.
            $table->foreign(['municipality_id', 'province_id'])
                ->references(['id', 'province_id'])
                ->on('municipalities')
                ->restrictOnDelete();
            $table->string('address', 255);
            // RN-003: hierarchy kept acyclic by the domain policy.
            $table->foreignId('parent_office_id')->nullable()->constrained('offices')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offices');
    }
};
