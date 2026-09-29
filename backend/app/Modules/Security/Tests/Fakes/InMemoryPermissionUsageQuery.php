<?php

declare(strict_types=1);

namespace App\Modules\Security\Tests\Fakes;

use App\Modules\Security\Application\Contracts\PermissionUsageQueryInterface;

/**
 * In-memory stand-in for the permission usage query port (ADR-11,
 * ADR-27): lets the PermissionService unit suite run without a
 * database while the tests control the custom-role and account
 * projections directly, mirroring the grouped pivot reads of the
 * Eloquent adapter.
 */
final class InMemoryPermissionUsageQuery implements PermissionUsageQueryInterface
{
    /**
     * Permission name => custom role names holding it.
     *
     * @var array<string, list<string>>
     */
    public array $customRoles = [];

    /**
     * Permission name => accounts that can act on it (deactivated
     * included, pivot reservation).
     *
     * @var array<string, int>
     */
    public array $usersCounts = [];

    /**
     * @return array<string, list<string>>
     */
    public function customRolesByPermission(): array
    {
        return $this->customRoles;
    }

    /**
     * @return array<string, int>
     */
    public function usersCountByPermission(): array
    {
        return $this->usersCounts;
    }
}
