<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application\Contracts;

/**
 * Read-only projection of pension cases handled per office
 * (RF-ENT-005 second part, ADR-28): how many expedientes each
 * office has tramitado.
 *
 * Dependency inversion across module boundaries: Organizations owns
 * the office surface but must not import PensionCases (deptrac),
 * so it declares this port and the PensionCases module binds the
 * implementation in its own provider — the data owner feeds the
 * consumer through the consumer's contract, the same direction the
 * authorization probes of PensionCases already use against
 * Organizations.
 *
 * Every case status counts (a case is being tramitado from the
 * moment it is captured) and soft-deleted cases stop counting; the
 * implementing query decides the physical shape, this port only
 * promises the office id => total map.
 */
interface OfficeCaseCountQueryInterface
{
    /**
     * Total of pension cases per office, all statuses included.
     *
     * @return array<int, int> office id => case count; offices without cases may be absent
     */
    public function countsByOffice(): array;
}
