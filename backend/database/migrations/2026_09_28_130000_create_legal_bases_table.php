<?php

declare(strict_types=1);

// Modelos de datos 5.6: legal_bases — Normas y resoluciones
// (RF-LEG-002..004). The type-number-year tern is unique and stays
// reserved by soft-deleted rows; the year is DERIVED from issue_date
// (H-11) and never travels on the wire; the date ordering (RN-006:
// effective_date >= issue_date, derogation_date >= effective_date)
// is validated in the service and backed by database CHECKs. Whether
// a norm is in force is derived at read time (LegalBasisStatus).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_bases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legal_basis_type_id')->constrained('legal_basis_types')->restrictOnDelete();
            $table->string('number', 30);
            $table->date('issue_date');
            $table->date('effective_date');
            $table->date('derogation_date')->nullable();
            $table->foreignId('issuing_organization_id')->constrained('organizations')->restrictOnDelete();
            // H-11: derived from issue_date, indexed for the year filter.
            $table->unsignedSmallInteger('year');
            $table->string('reference', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['legal_basis_type_id', 'number', 'year'], 'uq_legal_bases_type_number_year');
            $table->index('year');
        });

        // RN-006: the puesta en vigor never precedes the emisión…
        DB::statement(
            'ALTER TABLE legal_bases ADD CONSTRAINT chk_legal_basis_effective'
            .' CHECK (effective_date >= issue_date)'
        );
        // …and the derogación never precedes the puesta en vigor.
        DB::statement(
            'ALTER TABLE legal_bases ADD CONSTRAINT chk_legal_basis_derogation'
            .' CHECK (derogation_date IS NULL OR derogation_date >= effective_date)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_bases');
    }
};
