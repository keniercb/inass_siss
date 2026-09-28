<?php

declare(strict_types=1);

namespace App\Modules\Security\Domain\Authentication;

use App\Modules\Shared\Contracts\ClockInterface;
use DateTimeImmutable;

/**
 * Password lifecycle policy (RF-SEG-001, ADR-24): minimum length,
 * complexity (one upper, one lower, one digit) and the OPTIONAL
 * expiry counted in days from password_changed_at.
 *
 * Pure domain value: the Application layer builds it from
 * configuration (config/security.php) so this class never reads the
 * environment, and every date-sensitive answer resolves through the
 * Shared clock port (architecture doc section 12.4). Expiry is
 * inclusive: the renewal day counts as already expired, so users get
 * the prompt the morning the policy limit lands, not one day later.
 */
final readonly class PasswordPolicy
{
    public function __construct(
        public readonly int $minLength,
        public readonly bool $requireComplexity,
        public readonly ?int $maxAgeDays,
    ) {}

    /**
     * Violations of the static rules for a candidate password, in a
     * stable order (length, upper, lower, digit) so error messages
     * read consistently. Empty when the password conforms.
     *
     * @return list<string>
     */
    public function violations(string $password): array
    {
        $violations = [];

        if (mb_strlen($password) < $this->minLength) {
            $violations[] = 'min_length';
        }

        if ($this->requireComplexity) {
            if (preg_match('/\p{Lu}/u', $password) !== 1) {
                $violations[] = 'missing_uppercase';
            }

            if (preg_match('/\p{Ll}/u', $password) !== 1) {
                $violations[] = 'missing_lowercase';
            }

            if (preg_match('/\d/u', $password) !== 1) {
                $violations[] = 'missing_digit';
            }
        }

        return $violations;
    }

    /**
     * Whether the password has aged past the optional max and must be
     * renewed. Disabled policy (null max) or unknown baseline (null
     * changed-at, pre-migration accounts) never requires renewal: the
     * expiry is opt-in by configuration, not a silent default.
     */
    public function requiresRenewal(?DateTimeImmutable $passwordChangedAt, ClockInterface $clock): bool
    {
        if ($this->maxAgeDays === null || $passwordChangedAt === null) {
            return false;
        }

        $deadline = $passwordChangedAt
            ->setTimezone(new \DateTimeZone('UTC'))
            ->modify(sprintf('+%d days', $this->maxAgeDays));

        return $clock->now() >= $deadline;
    }
}
