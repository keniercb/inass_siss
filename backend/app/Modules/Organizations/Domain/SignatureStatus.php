<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain;

use App\Modules\Shared\Contracts\ClockInterface;

/**
 * Derived state of an authorized signature (RF-ENT-003): the
 * validity window is optional data, "being in force" is derived
 * state evaluated at read time — never a stored column, exactly like
 * the derived deceased flag of People. Boundary days are inclusive.
 */
enum SignatureStatus: string
{
    case Active = 'active';
    case Future = 'future';
    case Expired = 'expired';

    /**
     * Resolve the status of a Y-m-d window at "now" (Shared Clock
     * port: tests freeze it, production never calls time directly).
     * Null boundaries mean an open window: no start means valid since
     * forever, no end means valid until further notice. The
     * comparison is day-granular, so a window ending today stays in
     * force through the whole day.
     */
    public static function resolve(?string $validFrom, ?string $validTo, ClockInterface $clock): self
    {
        $today = $clock->now()->setTime(0, 0);

        if ($validFrom !== null && new \DateTimeImmutable($validFrom) > $today) {
            return self::Future;
        }

        if ($validTo !== null && new \DateTimeImmutable($validTo) < $today) {
            return self::Expired;
        }

        return self::Active;
    }
}
