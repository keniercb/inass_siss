<?php

declare(strict_types=1);

namespace App\Modules\Security\Domain\Authorization;

use InvalidArgumentException;

/**
 * Custom role naming contract (RF-SEG-002, ADR-26).
 *
 * Role names are natural keys shared with humans, seeders and import
 * tooling, so they follow one machine-friendly shape: a lowercase
 * slug of 2 to 31 characters built from letters, digits and
 * underscores, always starting with a letter. The five institutional
 * roles of section 2.2 are code-owned policy materialized from the
 * PermissionMatrix, so their names are permanently reserved: the
 * management surface can never shadow or mutate them by accident.
 *
 * Pure domain value: the FormRequest checks the format as a Rule and
 * the service re-checks everything (defense in depth), so any caller
 * — HTTP, console command or future importer — shares one
 * definition.
 */
final class RoleName
{
    private const string PATTERN = '/^[a-z][a-z0-9_]{1,30}$/';

    private function __construct(
        private readonly string $value,
    ) {}

    /**
     * @throws InvalidArgumentException when the name breaks the slug
     *                                  contract
     */
    public static function fromString(string $name): self
    {
        if (preg_match(self::PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'Role name [%s] must be a lowercase slug of 2-31 characters (letters, digits, underscores) starting with a letter.',
                    $name,
                ),
            );
        }

        return new self($name);
    }

    /**
     * Whether the name belongs to the institutional matrix (section
     * 2.2) and is therefore reserved for the PermissionMatrix.
     */
    public static function isReserved(string $name): bool
    {
        return in_array($name, PermissionMatrix::roles(), true);
    }

    public function value(): string
    {
        return $this->value;
    }
}
