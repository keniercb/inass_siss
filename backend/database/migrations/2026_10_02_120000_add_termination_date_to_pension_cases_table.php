<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 38 (user correction, SGP-32): the expediente gains the fecha de
 * desvinculación of the promovente — termination_date, an OPTIONAL
 * date (English column name, ADR-03 binding since Task 36).
 *
 * Pure additive nullable column: no constraint, no default — an
 * absent, null or empty wire value all persist NULL, exactly like
 * the promovente contact pair of Task 37. The shape rule (Y-m-d)
 * lives in the FormRequest; no semantic probe exists because the
 * user correction declares the field plain optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pension_cases', function (Blueprint $table): void {
            $table->date('termination_date')->nullable()->after('popular_council');
        });
    }

    public function down(): void
    {
        Schema::table('pension_cases', function (Blueprint $table): void {
            $table->dropColumn('termination_date');
        });
    }
};
