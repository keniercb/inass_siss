<?php

namespace Tests;

use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Authenticates a user holding one institutional role (RF-SEG-002,
     * section 2.2). Feature suites that exercise protected endpoints
     * seed the RBAC tables from the PermissionMatrix and act as the
     * given role; the returned user is the actor of the request.
     */
    protected function actingAsRole(string $role): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user);

        return $user;
    }
}
