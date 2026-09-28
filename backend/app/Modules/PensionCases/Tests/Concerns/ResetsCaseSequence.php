<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Tests\Concerns;

use App\Modules\Settings\Infrastructure\Persistence\Models\NumberingSequence;

/**
 * Test hygiene for the module suites that consume case numbers.
 *
 * Emissions travel through the dedicated `sequences` session
 * (ADR-17), so their commits survive the RefreshDatabase rollback of
 * the default connection. Without restoring the `pension_case`
 * scope, later suites — SettingsSeederTest asserts the pristine
 * seeded value — would see the numbers this module burned. The
 * module owns its emissions, so the module restores the row.
 */
trait ResetsCaseSequence
{
    protected function tearDown(): void
    {
        NumberingSequence::query()
            ->where('scope', 'pension_case')
            ->update(['next_value' => 1]);

        parent::tearDown();
    }
}
