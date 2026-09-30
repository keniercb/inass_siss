<?php

declare(strict_types=1);

// User rule 4: the case carries its pension classification — the
// pension type (catálogo pension_types: vejez, invalidez…) and the
// pension regime (catálogo pension_regimes, con sus meses por año) —
// plus the Ejército Rebelde membership pair: a boolean flag and the
// join date, REQUIRED when the flag is true and REJECTED when it is
// false, so the pair can never drift into an incoherent state. The
// wire contract (FormRequest + service) probes both layers; the
// CHECKs below are the last deterministic line of defense.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pension_cases', function (Blueprint $table): void {
            $table->foreignId('pension_type_id')
                ->after('scientific_category_id')
                ->constrained('pension_types')
                ->restrictOnDelete();
            $table->foreignId('pension_regime_id')
                ->after('pension_type_id')
                ->constrained('pension_regimes')
                ->restrictOnDelete();
            $table->boolean('rebel_army_member')
                ->after('last_salary')
                ->default(false);
            $table->date('rebel_army_join_date')
                ->after('rebel_army_member')
                ->nullable();
        });

        // The membership pair is coherent in exactly one shape: a
        // member always carries a join date, a non-member never does.
        DB::statement(
            'ALTER TABLE pension_cases ADD CONSTRAINT chk_pension_cases_rebel_army'
            .' CHECK (rebel_army_member = 0 OR rebel_army_join_date IS NOT NULL)'
        );
        DB::statement(
            'ALTER TABLE pension_cases ADD CONSTRAINT chk_pension_cases_rebel_army_inverse'
            .' CHECK (rebel_army_member = 1 OR rebel_army_join_date IS NULL)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pension_cases DROP CONSTRAINT chk_pension_cases_rebel_army');
        DB::statement('ALTER TABLE pension_cases DROP CONSTRAINT chk_pension_cases_rebel_army_inverse');

        Schema::table('pension_cases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pension_regime_id');
            $table->dropConstrainedForeignId('pension_type_id');
            $table->dropColumn(['rebel_army_member', 'rebel_army_join_date']);
        });
    }
};
