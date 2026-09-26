<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centralized numbering sequences (RF-PAG-006, data model section 5.3).
 *
 * One row per declared scope (bank_control, pension_case, ...). Each
 * emission locks the row with SELECT ... FOR UPDATE inside a
 * transaction on the dedicated "sequences" database session and
 * commits the increment independently of the caller's business
 * transaction: when business data rolls back, the emitted number
 * stays burned forever (RN-009 — numbers are never reused, gaps are
 * accepted by design). The UNIQUE scope is the natural key of RN-008:
 * a sequence exists at most once and is never silently created by an
 * emission. No soft deletes and no authorship columns — this is
 * transactional state, not business history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('numbering_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 40)->unique();
            $table->unsignedBigInteger('next_value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('numbering_sequences');
    }
};
