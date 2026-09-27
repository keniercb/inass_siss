<?php

declare(strict_types=1);

namespace App\Modules\People\Presentation\Rules;

use App\Modules\Shared\Support\CubanIdentityNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * Request rule that runs the Shared CubanIdentityNumber value object
 * (RN-001): 11 digits, century/gender prefix and the real-calendar
 * birth date encoded in positions 2..7. The check digit (P-08) stays
 * a deferred policy until the Ministry confirms the official
 * algorithm, so the rule enforces the full structural validation the
 * domain already owns — no duplicated logic here.
 */
final readonly class CubanIdentity implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        try {
            CubanIdentityNumber::fromString($value);
        } catch (InvalidArgumentException) {
            $fail('The :attribute is not structurally valid under the Cuban identity rules (RN-001).');
        }
    }
}
