<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 38 (user correction, SGP-32): the régimen de jubilación gains
 * an OPTIONAL sector — a plain nullable INTEGER with no domain
 * constraint (the user correction declares it optional and unbounded,
 * so unlike months_per_year there is no CHECK to declare).
 *
 * English column name (ADR-03 binding since Task 36). Pure additive:
 * existing rows — including the seeded General regime — keep NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pension_regimes', function (Blueprint $table): void {
            $table->integer('sector')->nullable()->after('months_per_year');
        });
    }

    public function down(): void
    {
        Schema::table('pension_regimes', function (Blueprint $table): void {
            $table->dropColumn('sector');
        });
    }
};
