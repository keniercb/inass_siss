<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application\Contracts;

/**
 * Read-only projection of the active users assigned to each office
 * (ADR-29): the office deactivation guard refuses to remove an
 * office from the active map while accounts still belong to it —
 * nobody silently loses their territorial scope.
 *
 * Dependency inversion across module boundaries (same shape as
 * OfficeCaseCountQueryInterface, ADR-28): Organizations owns the
 * deactivation rule but must not import Security, so it declares
 * this port and the Security module binds the implementation in
 * its own provider.
 */
interface OfficeAssignmentQueryInterface
{
    /**
     * Count of ACTIVE accounts whose office_id points at the office.
     * Deactivated accounts do not block the deactivation: they can
     * no longer authenticate, so their stale reference is harmless
     * (and restore-time consistency is documented in ADR-29).
     */
    public function countActiveUsers(int $officeId): int;
}
