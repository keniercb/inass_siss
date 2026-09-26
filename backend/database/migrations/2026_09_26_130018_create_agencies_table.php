<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agencies table (RF-CAT-003, data model section 5.1).
 *
 * Every agency keeps province and municipality for query convenience
 * (H-13), with the pair kept coherent by two database mechanisms: the
 * composite foreign key (municipality_id, province_id) ->
 * municipalities(id, province_id) makes a cross-province agency
 * physically impossible (RN-04), and the regular single-column FKs
 * keep each reference valid on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agencies', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 120);
            $table->foreignId('province_id')->constrained('provinces')->restrictOnDelete();
            $table->foreignId('municipality_id')->constrained('municipalities')->restrictOnDelete();
            $table->foreignId('agency_type_id')->constrained('agency_types')->restrictOnDelete();
            // RN-04: the municipality must belong to the declared province.
            $table->foreign(['municipality_id', 'province_id'])
                ->references(['id', 'province_id'])
                ->on('municipalities')
                ->restrictOnDelete();
            $table->index('name');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agencies');
    }
};
