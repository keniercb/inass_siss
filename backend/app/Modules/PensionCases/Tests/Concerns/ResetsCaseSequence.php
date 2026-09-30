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
 * scopes, later suites — SettingsSeederTest asserts the pristine
 * seeded value — would see the numbers this module burned. The
 * module owns its emissions, so the module restores the rows: the
 * plain legacy scope AND the annual `pension_case:{year}` rows
 * (ADR-32) this suite may have birthed or advanced.
 */
trait ResetsCaseSequence
{
    protected function tearDown(): void
    {
        NumberingSequence::query()
            ->where('scope', 'like', 'pension_case:%')
            ->orWhere('scope', 'pension_case')
            ->update(['next_value' => 1]);

        parent::tearDown();
    }
}
