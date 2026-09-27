<?php

declare(strict_types=1);

namespace App\Modules\People\Tests\Feature;

use App\Modules\People\Application\Contracts\PeopleServiceInterface;
use App\Modules\People\Infrastructure\Persistence\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Process eligibility by person state (S3.5, RF-SEG-003): living and
 * active people may start new processes; deceased or deactivated
 * people may not. The capability lives in the People module so
 * PensionCases (F3) enforces it at submission without coupling to
 * the People internals: the derived state is consulted, never
 * duplicated.
 */
final class PersonProcessEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private PeopleServiceInterface $people;

    protected function setUp(): void
    {
        parent::setUp();

        $this->people = $this->app->make(PeopleServiceInterface::class);
    }

    public function test_a_living_active_person_can_start_new_processes(): void
    {
        $person = Person::factory()->create();

        self::assertTrue($this->people->canStartNewProcess($person->id));
    }

    public function test_a_deceased_person_cannot_start_new_processes(): void
    {
        $person = Person::factory()->deceased('2020-01-01')->create();

        self::assertFalse($this->people->canStartNewProcess($person->id));
    }

    public function test_a_deactivated_person_cannot_start_new_processes(): void
    {
        $person = Person::factory()->create();
        $person->delete();

        self::assertFalse($this->people->canStartNewProcess($person->id));
    }

    public function test_an_unknown_person_fails_fast(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->people->canStartNewProcess(999999);
    }
}
