<?php

declare(strict_types=1);

// Modelos de datos 5.5: authorized_signatures — Firmas autorizadas
// por entidad (RF-ENT-003). The entity+person+position tern is unique
// and stays reserved by revoked (soft-deleted) rows, so the history
// of a signature cannot be overwritten: revocation is a state, not a
// deletion. The optional validity window keeps RN-006 ordering as a
// database CHECK, the last line behind the domain validation.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authorized_signatures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entity_id')->constrained('entities')->restrictOnDelete();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->foreignId('position_id')->constrained('positions')->restrictOnDelete();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['entity_id', 'person_id', 'position_id'], 'uq_signatures_entity_person_position');
        });

        // RN-006 style ordering: the end of a window never precedes its start.
        DB::statement(
            'ALTER TABLE authorized_signatures ADD CONSTRAINT chk_signature_dates'
            .' CHECK (valid_from IS NULL OR valid_to IS NULL OR valid_to >= valid_from)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('authorized_signatures');
    }
};
