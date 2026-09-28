<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Rules;

use App\Modules\Security\Application\Authentication\SecurityPolicies;
use App\Modules\Security\Domain\Authentication\PasswordPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Request rule that runs the pure PasswordPolicy domain value
 * (RF-SEC-001 "política de contraseñas", ADR-24): minimum length and
 * complexity (upper + lower + digit) as configured in
 * config/security.php. The rule delegates — no duplicated logic —
 * and the service re-checks the same domain value (defense in
 * depth), so the wire error and the use-case guarantee cannot drift.
 */
final readonly class ConformsToPasswordPolicy implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        $violations = $this->policy()->violations($value);

        if ($violations === []) {
            return;
        }

        $fail('The :attribute does not satisfy the password policy (minimum length and upper/lower/digit mix): '
            .implode(', ', $violations).'.');
    }

    private function policy(): PasswordPolicy
    {
        return SecurityPolicies::passwordFromConfig();
    }
}
