<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 38 (user correction, SGP-32): the tipo de pensión gains the
 * persona fallecida flag — deceased_person, a BOOLEAN with database
 * DEFAULT false (English column name, ADR-03 binding since Task 36).
 *
 * The default mirrors the applies_base_salary precedent of the
 * income concepts: an omitted store payload lets the column default
 * answer false instead of demanding the flag at the wire. Existing
 * rows — including the seeded types — read false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pension_types', function (Blueprint $table): void {
            $table->boolean('deceased_person')->default(false)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('pension_types', function (Blueprint $table): void {
            $table->dropColumn('deceased_person');
        });
    }
};
