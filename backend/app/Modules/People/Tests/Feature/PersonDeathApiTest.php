<?php

declare(strict_types=1);

namespace App\Modules\People\Tests\Feature;

use App\Modules\People\Infrastructure\Persistence\Models\Person;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Database\Factories\PersonFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Death registration POST /api/v1/people/{id}/death (RF-PER-003):
 * dated, audited and correctable through the same endpoint, with the
 * semantic guards (after birth, never in the future) and its visible
 * effect on searches.
 */
final class PersonDeathApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsRole('admin');
    }

    private function livingPerson(): Person
    {
        return Person::factory()->create([
            'first_name' => 'Carlos',
            'birth_date' => '1950-02-10',
            'identity_number' => PersonFactory::identity('M', '1950-02-10'),
        ]);
    }

    public function test_registers_a_death(): void
    {
        $person = $this->livingPerson();

        $this->postJson("/api/v1/people/{$person->id}/death", ['death_date' => '2024-03-10'])
            ->assertOk()
            ->assertJsonPath('data.death_date', '2024-03-10')
            ->assertJsonPath('data.deceased', true);

        $this->assertDatabaseHas('people', [
            'id' => $person->id,
            'death_date' => '2024-03-10',
        ]);
    }

    public function test_death_registration_is_audited_with_the_previous_value(): void
    {
        $person = $this->livingPerson();

        $this->postJson("/api/v1/people/{$person->id}/death", ['death_date' => '2024-03-10'])
            ->assertOk();

        // RF-PER-003: the registration is datable and auditable (who
        // and when live in the trail entry).
        $this->assertDatabaseHas('activity_log', [
            'event' => 'updated',
            'subject_type' => Person::class,
            'subject_id' => $person->id,
            'causer_id' => $this->user->id,
        ]);

        $entry = Activity::query()
            ->where('subject_type', Person::class)
            ->where('subject_id', $person->id)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $properties = $entry->properties;
        $this->assertNotNull($properties);
        $old = $properties->get('old');
        $this->assertIsArray($old);
        $this->assertArrayHasKey('death_date', $old);
        $this->assertNull($old['death_date']);
        $attributes = $properties->get('attributes');
        $this->assertIsArray($attributes);
        // The trail stores the raw serialized change values, so a
        // DATE column lands with its full datetime spelling.
        $this->assertStringStartsWith('2024-03-10', (string) ($attributes['death_date'] ?? ''));
    }

    public function test_death_date_is_required(): void
    {
        $person = $this->livingPerson();

        $this->postJson("/api/v1/people/{$person->id}/death", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['death_date']);
    }

    public function test_death_date_must_use_the_iso_format(): void
    {
        $person = $this->livingPerson();

        $this->postJson("/api/v1/people/{$person->id}/death", ['death_date' => '10-03-2024'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['death_date']);
    }

    public function test_death_must_come_strictly_after_birth(): void
    {
        $person = $this->livingPerson();

        // Same day is rejected: the CHECK enforces death_date > birth_date.
        $this->postJson("/api/v1/people/{$person->id}/death", ['death_date' => '1950-02-10'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['death_date']);

        $this->postJson("/api/v1/people/{$person->id}/death", ['death_date' => '1949-01-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['death_date']);
    }

    public function test_death_cannot_be_in_the_future(): void
    {
        $person = $this->livingPerson();

        $this->postJson("/api/v1/people/{$person->id}/death", ['death_date' => '2100-01-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['death_date']);
    }

    public function test_death_registration_is_correctable_through_the_same_endpoint(): void
    {
        $person = $this->livingPerson();

        $this->postJson("/api/v1/people/{$person->id}/death", ['death_date' => '2024-03-10'])
            ->assertOk();

        // Data-entry errors are corrected by registering the death
        // again: the trail keeps both the previous and the new date.
        $this->postJson("/api/v1/people/{$person->id}/death", ['death_date' => '2024-03-09'])
            ->assertOk()
            ->assertJsonPath('data.death_date', '2024-03-09');

        $entry = Activity::query()
            ->where('subject_type', Person::class)
            ->where('subject_id', $person->id)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $properties = $entry->properties;
        $this->assertNotNull($properties);
        $old = $properties->get('old');
        $this->assertIsArray($old);
        $attributes = $properties->get('attributes');
        $this->assertIsArray($attributes);
        $this->assertStringStartsWith('2024-03-10', (string) ($old['death_date'] ?? ''));
        $this->assertStringStartsWith('2024-03-09', (string) ($attributes['death_date'] ?? ''));
    }

    public function test_returns_404_for_an_unknown_person(): void
    {
        $this->postJson('/api/v1/people/999999/death', ['death_date' => '2024-03-10'])
            ->assertNotFound();
    }

    public function test_deceased_people_are_flagged_in_searches(): void
    {
        // RF-PER-003: the effect of the death on searches is the flag.
        $person = $this->livingPerson();

        $this->getJson("/api/v1/people?identity={$person->identity_number}")
            ->assertOk()
            ->assertJsonPath('data.0.deceased', false);

        $this->postJson("/api/v1/people/{$person->id}/death", ['death_date' => '2024-03-10'])
            ->assertOk();

        $this->getJson("/api/v1/people?identity={$person->identity_number}")
            ->assertOk()
            ->assertJsonPath('data.0.deceased', true)
            ->assertJsonPath('data.0.death_date', '2024-03-10');
    }

    public function test_database_backstop_rejects_a_death_before_birth(): void
    {
        // CHECK chk_people_dates: the service validates first; the
        // constraint is the last line of defense (RN-008 convention).
        $this->expectException(QueryException::class);

        Person::factory()->create([
            'birth_date' => '1950-02-10',
            'identity_number' => PersonFactory::identity('M', '1950-02-10'),
            'death_date' => '1950-02-09',
        ]);
    }
}
