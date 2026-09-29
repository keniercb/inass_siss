<?php

declare(strict_types=1);

namespace App\Modules\Security\Infrastructure\Persistence;

use App\Modules\Organizations\Application\Contracts\OfficeAssignmentQueryInterface;
use App\Modules\Security\Infrastructure\Persistence\Models\User;

/**
 * Eloquent projection feeding the office deactivation guard of the
 * Organizations module (ADR-29).
 *
 * Implements the port declared by the consumer — deptrac allows
 * Security to import Organizations (mirroring the person link of
 * S3.5), never the reverse. The User model carries SoftDeletes, so
 * the count answers with active accounts only: a deactivated user
 * can no longer authenticate and therefore no longer blocks its
 * office from leaving the active map.
 */
final class OfficeAssignmentQuery implements OfficeAssignmentQueryInterface
{
    public function countActiveUsers(int $officeId): int
    {
        return User::query()
            ->where('office_id', $officeId)
            ->count();
    }
}
