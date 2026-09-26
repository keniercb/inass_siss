<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Municipalities table (RF-CAT-002, data model section 5.1).
 *
 * The (province_id, code) pair is the natural key: two municipalities
 * from different provinces may share a code (RN-008). province_id is
 * nullable to model the special municipality Isla de la Juventud,
 * which belongs to no province. The UNIQUE(id, province_id) index
 * backs the composite foreign key that agencies use, so MySQL itself
 * guarantees the municipality-province coherence rule (RN-04).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipalities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('province_id')->nullable()->constrained('provinces')->restrictOnDelete();
            $table->string('code', 4);
            $table->string('name', 80);
            $table->unique(['province_id', 'code']);
            $table->index('name');
            // Backing index for the agencies composite FK (RN-04).
            $table->unique(['id', 'province_id']);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipalities');
    }
};
