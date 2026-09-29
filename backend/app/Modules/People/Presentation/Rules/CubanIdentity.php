<?php

declare(strict_types=1);

namespace App\Modules\People\Presentation\Rules;

use App\Modules\Shared\Support\CubanIdentityNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * Request rule that runs the Shared CubanIdentityNumber value object
 * (RN-001): 11 digits, month 01-12 in digits 3-4 and day 01-31 in
 * digits 5-6 — the year block and the registry sequence stay
 * unvalidated. When the declared sex is provided, the rule also
 * cross-checks it against the parity of digit 10 (even male, odd
 * female), which is the only sex-sensitive validation the surface
 * owns — no duplicated structure logic here, the value object is
 * the authority.
 */
final readonly class CubanIdentity implements ValidationRule
{
    public function __construct(
        private readonly ?string $declaredSex = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        try {
            $identity = CubanIdentityNumber::fromString($value);
        } catch (InvalidArgumentException) {
            $fail('The :attribute is not structurally valid under the Cuban identity rules (RN-001).');

            return;
        }

        if ($this->declaredSex !== null && $identity->gender() !== $this->declaredSex) {
            $fail('The :attribute digit 10 encodes a different sex than the declared one (even male, odd female).');
        }
    }
}
