<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cargos catalog table (RF-CAT-001, data model sections 5.1/5.2).
 *
 * Natural keys are guaranteed by database constraints (RN-008). The
 * logical deactivation required by RF-CAT-001 uses soft deletes, and
 * authorship columns are stamped by the Shared AuditableObserver
 * (ADR-14) on every create/update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('description', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
