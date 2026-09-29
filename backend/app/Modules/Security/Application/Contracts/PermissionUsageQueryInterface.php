<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\Contracts;

/**
 * Read-only usage projection over the permission pivots (RF-SEG-002,
 * ADR-27).
 *
 * Declared in the Application layer so the catalog use cases depend
 * on the abstraction (DIP, architecture doc section 7) while the
 * Eloquent implementation stays confined to Infrastructure. The
 * PermissionMatrix owns WHAT the permissions are; this port answers
 * WHO holds them at runtime: the custom roles that bundle each
 * permission and the accounts that can act on it. Read-only by
 * construction — the catalog has no mutation pathway anywhere, so
 * swapping the store or injecting an in-memory fake in tests never
 * touches business logic.
 */
interface PermissionUsageQueryInterface
{
    /**
     * Custom (non-institutional) role names holding each permission,
     * keyed by permission name. Institutional holders are NOT part of
     * this projection: they come from the PermissionMatrix itself,
     * which is their single source of truth.
     *
     * @return array<string, list<string>>
     */
    public function customRolesByPermission(): array;

    /**
     * Distinct accounts that can act on each permission through the
     * roles granting it, keyed by permission name. Deactivation does
     * not release the role pivots (ADR-24), so deactivated accounts
     * keep counting — the same reservation the role directory and
     * its deletion guard project.
     *
     * @return array<string, int>
     */
    public function usersCountByPermission(): array;
}
