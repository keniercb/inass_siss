<?php

declare(strict_types=1);

namespace App\Modules\Settings\Application\Exceptions;

use RuntimeException;

/**
 * Raised when someone tries to delete a settings version that is
 * already in force or has already been in force (RF-CAT-005/RN-007).
 *
 * History must stay reproducible because calculations freeze the
 * version they used; only future vigencias that have not taken effect
 * yet may be removed. The controller translates this into HTTP 409.
 */
final class VersionAlreadyEffectiveException extends RuntimeException
{
    public static function forDate(string $effectiveFrom): self
    {
        return new self(
            "The version effective from {$effectiveFrom} is already in effect and cannot be deleted.",
        );
    }
}
