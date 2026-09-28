<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Brute-force lockout and password lifecycle state (RF-SEG-001,
     * ADR-24): failed-login bookkeeping and the optional-expiry
     * baseline. `locked_at` plus the policy TTL derives the locked
     * state at read time (no unlocked_at column to keep in sync);
     * `password_changed_at` anchors the optional caducidad, backfilled
     * with the creation date so pre-existing accounts keep a baseline.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('failed_login_attempts')->default(0)->after('password');
            $table->timestamp('locked_at')->nullable()->after('failed_login_attempts');
            $table->timestamp('password_changed_at')->nullable()->after('locked_at');
        });

        DB::table('users')
            ->whereNull('password_changed_at')
            ->update(['password_changed_at' => DB::raw('`created_at`')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['failed_login_attempts', 'locked_at', 'password_changed_at']);
        });
    }
};
