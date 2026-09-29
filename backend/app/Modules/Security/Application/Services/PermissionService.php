<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\Services;

use App\Modules\Security\Application\Contracts\PermissionServiceInterface;
use App\Modules\Security\Application\Contracts\PermissionUsageQueryInterface;
use App\Modules\Security\Application\DTO\PermissionEntry;
use App\Modules\Security\Domain\Authorization\PermissionMatrix;
use Illuminate\Support\Collection;

/**
 * Permission catalog use cases (RF-SEG-002, ADR-27).
 *
 * The catalog answers to the PermissionMatrix — permissions are
 * code-owned artifacts, so the matrix decides which entries exist
 * and which institutional roles grant them, while the usage port
 * contributes the live projections (custom holders and effective
 * accounts). Anything the runtime knows that the matrix does not is
 * deliberately ignored: the catalog stays closed, so a stray pivot
 * row for a permission that left the matrix can never surface.
 *
 * Read-only by construction: the surface the role editor consumes
 * has no mutation pathway — creating or editing permissions at
 * runtime would desynchronize the code (dataset, seeder, middleware
 * expectations) from the database. Persistence is delegated to the
 * usage port, so the service stays unit-testable without a database
 * (ADR-11).
 */
final class PermissionService implements PermissionServiceInterface
{
    public function __construct(
        private readonly PermissionUsageQueryInterface $usage,
    ) {}

    public function list(): Collection
    {
        return new Collection(array_values($this->buildEntries()));
    }

    public function find(string $permission): ?PermissionEntry
    {
        return $this->buildEntries()[$permission] ?? null;
    }

    /**
     * Every catalog entry keyed by permission name. Institutional
     * holders follow the matrix order (section 2.2) and custom
     * holders arrive name-ordered — the same order the role
     * directory projects, so both surfaces agree.
     *
     * @return array<string, PermissionEntry>
     */
    private function buildEntries(): array
    {
        $customRoles = $this->usage->customRolesByPermission();
        $usersCounts = $this->usage->usersCountByPermission();

        $entries = [];

        foreach (PermissionMatrix::permissions() as $permission) {
            $customHolders = $customRoles[$permission] ?? [];
            sort($customHolders);

            $entries[$permission] = PermissionEntry::fromCatalog(
                $permission,
                $this->institutionalHolders($permission),
                $customHolders,
                (int) ($usersCounts[$permission] ?? 0),
            );
        }

        return $entries;
    }

    /**
     * Institutional holders straight from the matrix grants, in
     * section 2.2 order. The five roles are code-owned policy: the
     * seeder converges the database to this truth, so the catalog
     * reads it directly instead of trusting the pivot state.
     *
     * @return list<string>
     */
    private function institutionalHolders(string $permission): array
    {
        $holders = [];

        foreach (PermissionMatrix::roles() as $role) {
            if (PermissionMatrix::roleHasPermission($role, $permission)) {
                $holders[] = $role;
            }
        }

        return $holders;
    }
}
