<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Infrastructure\Persistence;

use App\Modules\Organizations\Application\Contracts\OfficeCaseCountQueryInterface;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\PensionCase;

/**
 * Eloquent projection feeding the Organizations office counts
 * (RF-ENT-005 second part, ADR-28).
 *
 * Implements the port declared by the consumer module — deptrac
 * allows PensionCases to import Organizations, never the reverse.
 * One grouped query answers the whole map: the territorial
 * structure is small (dozens of offices) and both the tree and the
 * detail aggregate over the complete active hierarchy anyway.
 *
 * The model carries SoftDeletes, so discarded cases stop counting
 * without any extra condition, and every status is counted because
 * an expediente is being tramitado from the moment it is captured
 * until it is resolved.
 */
final class OfficeCaseCountQuery implements OfficeCaseCountQueryInterface
{
    public function countsByOffice(): array
    {
        return PensionCase::query()
            ->selectRaw('office_id, COUNT(*) as total')
            ->groupBy('office_id')
            ->pluck('total', 'office_id')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }
}
