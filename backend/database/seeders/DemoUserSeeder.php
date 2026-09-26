<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Illuminate\Database\Seeder;

/**
 * Idempotent demo user for phase 0 (login demo in staging).
 * Credentials are for DEMO ONLY and must be rotated before production
 * (phase 7 hardening checklist).
 */
class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@sgp.local'],
            [
                'name' => 'SGP Demo Admin',
                'password' => 'password',
            ],
        );
    }
}
