<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned general settings table (RF-CAT-005, data model section 5.3).
 *
 * One row per vigencia: parameters are immutable once created (edits
 * create a new version) and effective_from carries a UNIQUE constraint,
 * which under the "greatest effective_from <= date" resolution rule of
 * RN-007 is a complete no-overlap guarantee enforced by the database
 * (RN-008). No soft deletes: history must stay reproducible because
 * calculations freeze the version they used; only versions that have
 * not taken effect yet may be removed, and the future
 * pension_cases.calculation_setting_id FK will restrict deletes of
 * versions that were used by a calculation. Authorship is stamped by
 * the Shared AuditableObserver (ADR-14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('general_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('min_work_years');
            $table->unsignedInteger('min_age_men');
            $table->unsignedInteger('min_age_women');
            $table->unsignedInteger('base_calc_percent');
            $table->unsignedInteger('max_calc_percent');
            $table->unsignedInteger('annual_increase_percent');
            $table->date('effective_from')->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE general_settings ADD CONSTRAINT chk_general_settings_percent_range CHECK (max_calc_percent >= base_calc_percent)');
    }

    public function down(): void
    {
        Schema::dropIfExists('general_settings');
    }
};
