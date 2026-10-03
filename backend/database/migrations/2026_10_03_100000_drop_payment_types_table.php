<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 42 (user correction, SGP-36): the payment_types catalog is
 * ELIMINATED — the payment form of the collection now lives in the
 * agency type (agency_types.payment_form), so the transversal
 * catalog has no reason to exist anymore.
 *
 * Nothing references it: the pension_payments proposal (H-16) is not
 * built, the Payments module is empty scaffolding and the registry
 * entry declares no dependents — the drop is clean.
 *
 * down() recreates the table with its FINAL shape (code + name +
 * description, the Task 31 natural key) so a rollback restores the
 * catalog surface the registry of that revision expects, not the
 * pre-Task-31 one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('payment_types');
    }

    public function down(): void
    {
        Schema::create('payment_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 80)->unique();
            $table->string('description', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
