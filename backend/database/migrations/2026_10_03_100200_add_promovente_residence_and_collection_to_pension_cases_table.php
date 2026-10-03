<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 42 (user correction, SGP-36): the case gains the promovente
 * RESIDENCE and COLLECTION group — six columns, every one required
 * at the wire EXCEPT the bank account:
 *
 * - current_address VARCHAR(255) NOT NULL — the promovente's current
 *   address (people.address stays the historical registry one).
 * - residence_province_id / residence_municipality_id FK NOT NULL —
 *   the residence geography; the municipality-belongs-to-province
 *   coherence (RN-04) is probed in the application (422), with the
 *   special municipality (Isla de la Juventud, province NULL) out of
 *   the residence domain — documented as open question P-09.
 * - collection_agency_type_id / collection_agency_id FK NOT NULL —
 *   the collection point; the agency must be ACTIVE and of the
 *   declared type (probed, 422).
 * - bank_account VARCHAR(34) NULL — REQUIRED CONDITIONALLY: the
 *   service demands it (422 on bank_account) when the payment form
 *   of the collection agency type is 'tarjeta magnetica'; optional
 *   with 'nomina electronica'. The whole group is EDITABLE through
 *   the PUT (explicit user decision), with the conditional demand
 *   re-evaluated against the RESULTING state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pension_cases', function (Blueprint $table): void {
            $table->string('current_address', 255)->after('termination_date');
            $table->foreignId('residence_province_id')->after('current_address')->constrained('provinces')->restrictOnDelete();
            $table->foreignId('residence_municipality_id')->after('residence_province_id')->constrained('municipalities')->restrictOnDelete();
            $table->foreignId('collection_agency_type_id')->after('residence_municipality_id')->constrained('agency_types')->restrictOnDelete();
            $table->foreignId('collection_agency_id')->after('collection_agency_type_id')->constrained('agencies')->restrictOnDelete();
            $table->string('bank_account', 34)->nullable()->after('collection_agency_id');
        });
    }

    public function down(): void
    {
        Schema::table('pension_cases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('collection_agency_id');
            $table->dropConstrainedForeignId('collection_agency_type_id');
            $table->dropConstrainedForeignId('residence_municipality_id');
            $table->dropConstrainedForeignId('residence_province_id');
            $table->dropColumn(['current_address', 'bank_account']);
        });
    }
};
