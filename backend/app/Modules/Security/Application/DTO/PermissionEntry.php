<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\DTO;

use InvalidArgumentException;

/**
 * One entry of the permission catalog, shaped for the read surface
 * (RF-SEG-002, ADR-27): the permission name with its module.action
 * decomposition, the institutional roles granting it (from the
 * PermissionMatrix — their single source of truth), the custom roles
 * bundling it (live projection) and the count of accounts that can
 * act on it (deactivated included: their pivots keep reserving the
 * grants).
 */
final class PermissionEntry
{
    /**
     * @param  list<string>  $institutionalRoles
     * @param  list<string>  $customRoles
     */
    private function __construct(
        public readonly string $name,
        public readonly string $module,
        public readonly string $action,
        public readonly array $institutionalRoles,
        public readonly array $customRoles,
        public readonly int $usersCount,
    ) {}

    /**
     * Builds an entry from a catalog name. The modulo.accion
     * convention is a structural invariant of the catalog (the route
     * pattern and this decomposition both rely on exactly one dot),
     * so a malformed name fails loudly: it is a developer error in
     * the PermissionMatrix, never user input.
     *
     * @param  list<string>  $institutionalRoles
     * @param  list<string>  $customRoles
     */
    public static function fromCatalog(
        string $name,
        array $institutionalRoles,
        array $customRoles,
        int $usersCount,
    ): self {
        $segments = explode('.', $name);

        if (count($segments) !== 2 || $segments[0] === '' || $segments[1] === '') {
            throw new InvalidArgumentException(
                sprintf('Permission [%s] must follow the module.action convention.', $name),
            );
        }

        return new self(
            $name,
            $segments[0],
            $segments[1],
            $institutionalRoles,
            $customRoles,
            $usersCount,
        );
    }
}
