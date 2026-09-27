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
        $user = User::firstOrCreate(
            ['email' => 'admin@sgp.local'],
            [
                'name' => 'SGP Demo Admin',
                'password' => 'password',
            ],
        );

        // The demo account holds the admin role (RF-SEG-002): staging
        // keeps full access out of the box. Runs after
        // RolesAndPermissionsSeeder, and assignRole is pivot-idempotent.
        $user->assignRole('admin');
    }
}
