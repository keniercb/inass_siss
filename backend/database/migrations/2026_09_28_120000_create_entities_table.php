<?php

declare(strict_types=1);

// Modelos de datos 5.5: entities — Entidades empleadoras / centros de
// trabajo (RF-ENT-001). Code and NIT are unique and stay reserved by
// soft-deleted rows; the geographic pair keeps the two database
// mechanisms of RN-04 (single-column FKs plus the composite key that
// makes a cross-province entity physically impossible); the
// self-referenced hierarchy is kept acyclic by the domain
// HierarchyPolicy (RN-003) because no database constraint can
// express "no cycles".

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entities', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 15)->unique();
            $table->string('tax_id_number', 20)->unique();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('province_id')->constrained('provinces')->restrictOnDelete();
            $table->foreignId('municipality_id')->constrained('municipalities')->restrictOnDelete();
            // RN-04: the municipality must belong to the declared province.
            $table->foreign(['municipality_id', 'province_id'])
                ->references(['id', 'province_id'])
                ->on('municipalities')
                ->restrictOnDelete();
            $table->foreignId('entity_type_id')->constrained('entity_types')->restrictOnDelete();
            $table->string('address', 255);
            $table->string('phone', 30)->nullable();
            $table->string('fax', 30)->nullable();
            $table->string('email', 120)->nullable();
            $table->foreignId('director_person_id')->nullable()->constrained('people')->restrictOnDelete();
            $table->foreignId('economic_director_person_id')->nullable()->constrained('people')->restrictOnDelete();
            // RN-003: hierarchy kept acyclic by the domain policy.
            $table->foreignId('parent_entity_id')->nullable()->constrained('entities')->restrictOnDelete();
            $table->text('social_purpose');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entities');
    }
};
