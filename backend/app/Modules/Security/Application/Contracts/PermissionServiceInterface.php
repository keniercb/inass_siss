<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\Contracts;

use App\Modules\Security\Application\DTO\PermissionEntry;
use Illuminate\Support\Collection;

/**
 * Permission catalog use cases (RF-SEG-002, ADR-27).
 *
 * Contract for the read surface the role editor consumes: the
 * catalog answers to the PermissionMatrix (code-owned single source
 * of truth — permissions are artifacts of the code, never runtime
 * rows), so this service exposes exactly two listing operations and
 * no mutation pathway exists anywhere in the application. Controllers
 * depend on this abstraction (DIP, ADR-12) so the catalog surface
 * stays unit-testable with a fake usage projection.
 */
interface PermissionServiceInterface
{
    /**
     * Every catalog permission with its module.action decomposition,
     * its institutional holders (from the matrix), the custom roles
     * bundling it (live projection) and the count of accounts that
     * can act on it (deactivated included).
     *
     * @return Collection<int, PermissionEntry>
     */
    public function list(): Collection;

    /**
     * One catalog permission by its natural name, or null when the
     * name is not part of the catalog (the caller answers 404).
     */
    public function find(string $permission): ?PermissionEntry;
}
