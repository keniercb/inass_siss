<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 42 (user correction, SGP-36): the agency type gains the
 * payment form of the collection — payment_form, an enum of TWO
 * values written exactly as the user fixed them (lowercase unified,
 * no tildes): 'tarjeta magnetica' and 'nomina electronica'.
 *
 * The column is NOT NULL with DEFAULT 'tarjeta magnetica' — the
 * deceased_person precedent of Task 38: an omitted store payload
 * lets the column default answer instead of demanding the flag at
 * the wire, and the in-memory model default mirrors it so the 201
 * projection answers without a reload. Existing rows — including
 * the seeded types — read the default. The CHECK backs the enum as
 * the last deterministic line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_types', function (Blueprint $table): void {
            $table->string('payment_form', 20)->default('tarjeta magnetica')->after('name');
        });

        DB::statement(
            'ALTER TABLE agency_types ADD CONSTRAINT chk_agency_types_payment_form'
            ." CHECK (payment_form IN ('tarjeta magnetica', 'nomina electronica'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE agency_types DROP CONSTRAINT chk_agency_types_payment_form');

        Schema::table('agency_types', function (Blueprint $table): void {
            $table->dropColumn('payment_form');
        });
    }
};
