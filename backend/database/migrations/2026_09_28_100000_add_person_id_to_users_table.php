<?php

declare(strict_types=1);

// Modelos de datos 5.4: users.person_id — Asociación usuario↔persona
// (S3.5, RF-SEG-004). Nullable: not every account needs a natural
// person yet; unique: one person backs at most one account (the
// constraint reserves the link even against soft-deleted accounts,
// mirroring the identity reservation of people); restrict: a linked
// person can never be physically removed.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('person_id')
                ->nullable()
                ->after('password')
                ->constrained('people')
                ->restrictOnDelete();

            $table->unique('person_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['person_id']);
            $table->dropUnique(['person_id']);
            $table->dropColumn('person_id');
        });
    }
};
