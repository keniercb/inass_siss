<?php

declare(strict_types=1);

namespace App\Modules\Shared\Exceptions;

use RuntimeException;

/**
 * Raised when a sequence emission asks for a scope that was never
 * declared (RF-PAG-006, ADR-17).
 *
 * Sequences are declared up front by the SettingsSeeder; an unknown
 * scope is an operational misconfiguration and must fail loudly
 * instead of silently creating rows. The exception lives in Shared
 * because the SequenceGeneratorInterface port belongs to Shared: any
 * consumer module (PensionCases in phase 3, Payments in phase 5) can
 * catch it without depending on the Settings module that owns the
 * MySQL adapter.
 */
final class UnknownSequenceException extends RuntimeException
{
    public static function forScope(string $scope): self
    {
        return new self(
            "The sequence [{$scope}] is not declared. Declare it in the SettingsSeeder before emitting numbers.",
        );
    }
}
