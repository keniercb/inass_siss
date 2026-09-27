<?php

declare(strict_types=1);

// Modelos de datos 5.4: people — Personas (RF-PER-001..005). Identity
// is unique and immutable (RN-001), sex and death/birth ordering are
// guaranteed by CHECKs, and the search index (idx_people_names)
// serves the surname-first listing order.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table): void {
            $table->id();
            $table->string('identity_number', 11);
            $table->string('first_name', 50);
            $table->string('middle_name', 50)->nullable();
            $table->string('first_surname', 50);
            $table->string('second_surname', 50)->nullable();
            $table->char('sex', 1);
            $table->foreignId('race_id')->nullable()->constrained('races')->restrictOnDelete();
            $table->string('address', 255);
            $table->date('birth_date');
            $table->date('death_date')->nullable();
            $table->string('father_name', 120)->nullable();
            $table->string('mother_name', 120)->nullable();
            $table->string('citizen_card_id', 30)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('identity_number');
            $table->unique('citizen_card_id');
            $table->index(['first_surname', 'first_name', 'birth_date'], 'idx_people_names');
        });

        DB::statement("ALTER TABLE people ADD CONSTRAINT chk_people_sex CHECK (sex IN ('M', 'F'))");
        DB::statement('ALTER TABLE people ADD CONSTRAINT chk_people_dates CHECK (death_date IS NULL OR death_date > birth_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
