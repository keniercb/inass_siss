<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\Authentication;

use App\Modules\Security\Domain\Authentication\LockoutPolicy;
use App\Modules\Security\Domain\Authentication\PasswordPolicy;

/**
 * Bridges configuration and the pure security domain values (ADR-24).
 *
 * The domain values never read config (domain purity, architecture
 * doc section 7): this Application-level factory translates the
 * config/security.php knobs into PasswordPolicy/LockoutPolicy
 * instances. The service provider calls it INSIDE its binding
 * closures, so every container resolution rebuilds the policies and
 * runtime configuration overrides (config()->set in feature tests)
 * take effect on the very next request.
 */
final class SecurityPolicies
{
    public static function passwordFromConfig(): PasswordPolicy
    {
        /** @var array{min_length?: int, require_complexity?: bool, max_age_days?: int|null} $password */
        $password = config('security.password', []);

        return new PasswordPolicy(
            minLength: (int) ($password['min_length'] ?? 10),
            requireComplexity: (bool) ($password['require_complexity'] ?? true),
            maxAgeDays: array_key_exists('max_age_days', $password) && $password['max_age_days'] !== null
                ? (int) $password['max_age_days']
                : null,
        );
    }

    public static function lockoutFromConfig(): LockoutPolicy
    {
        /** @var array{max_attempts?: int, ttl_seconds?: int} $lockout */
        $lockout = config('security.lockout', []);

        return new LockoutPolicy(
            maxAttempts: (int) ($lockout['max_attempts'] ?? 5),
            ttlSeconds: (int) ($lockout['ttl_seconds'] ?? 900),
        );
    }
}
