<?php

declare(strict_types=1);

namespace App\Modules\People\Tests\Feature;

use App\Modules\People\Infrastructure\Persistence\Models\Person;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Duplicate control at registration (RF-PER-005, DA H-08): an
 * already-registered identity answers 409 with the registered person
 * (even soft-deleted, RN-001), homonym candidates warn the operator
 * unless the request is confirmed, and the optional citizen card
 * stays unique.
 */
final class PersonDuplicatesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsRole('admin');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'identity_number' => '85061510002',
            'first_name' => 'Juan',
            'first_surname' => 'Pérez',
            'sex' => 'M',
            'address' => 'Calle 23 #45, Vedado, La Habana',
            'birth_date' => '1985-06-15',
        ], $overrides);
    }

    public function test_a_duplicate_identity_answers_409_with_the_registered_person(): void
    {
        $existing = Person::factory()->create([
            'identity_number' => '85061510002',
            'first_name' => 'Juan',
            'first_surname' => 'Pérez',
            'birth_date' => '1985-06-15',
        ]);

        $response = $this->postJson('/api/v1/people', $this->payload());

        $response->assertConflict()
            ->assertJsonPath('person.id', $existing->id)
            ->assertJsonPath('person.identity_number', '85061510002')
            ->assertJsonPath('person.first_name', 'Juan')
            ->assertJsonPath('person.birth_date', '1985-06-15');

        // No second row: the registry keeps exactly one person per
        // identity (RN-001).
        $this->assertDatabaseCount('people', 1);
    }

    public function test_a_duplicate_identity_is_blocked_even_after_a_soft_delete(): void
    {
        $existing = Person::factory()->create(['identity_number' => '85061510002']);
        $existing->delete();

        // RN-001: the identity is unique against deactivated persons
        // too — the database UNIQUE index covers soft-deleted rows, so
        // reusing the identity of a deactivated person is refused.
        $this->postJson('/api/v1/people', $this->payload())
            ->assertConflict()
            ->assertJsonPath('person.id', $existing->id);
    }

    public function test_homonym_candidates_warn_the_operator(): void
    {
        Person::factory()->create([
            'identity_number' => '85061510004',
            'first_name' => 'Juan',
            'first_surname' => 'Pérez',
            'birth_date' => '1985-06-15',
        ]);

        $response = $this->postJson('/api/v1/people', $this->payload());

        $response->assertConflict()
            ->assertJsonPath('candidates.0.identity_number', '85061510004')
            ->assertJsonPath('candidates.0.first_name', 'Juan')
            ->assertJsonPath('candidates.0.birth_date', '1985-06-15');

        $this->assertDatabaseCount('people', 1);
    }

    public function test_a_confirmed_homonym_is_registered(): void
    {
        Person::factory()->create([
            'identity_number' => '85061510004',
            'first_name' => 'Juan',
            'first_surname' => 'Pérez',
            'birth_date' => '1985-06-15',
        ]);

        $this->postJson('/api/v1/people', $this->payload(['confirm' => true]))
            ->assertCreated();

        $this->assertDatabaseCount('people', 2);
    }

    public function test_homonyms_of_a_deceased_person_do_not_warn(): void
    {
        // Only living people are duplicate candidates: a deceased
        // homonym is history, not a registry conflict.
        Person::factory()->create([
            'identity_number' => '85061510004',
            'first_name' => 'Juan',
            'first_surname' => 'Pérez',
            'birth_date' => '1985-06-15',
            'death_date' => '2020-04-01',
        ]);

        $this->postJson('/api/v1/people', $this->payload())
            ->assertCreated();
    }

    public function test_homonym_detection_ignores_a_different_birth_date(): void
    {
        Person::factory()->create([
            'identity_number' => '85061510004',
            'first_name' => 'Juan',
            'first_surname' => 'Pérez',
            'birth_date' => '1986-01-01',
        ]);

        $this->postJson('/api/v1/people', $this->payload())
            ->assertCreated();
    }

    public function test_a_unique_person_registers_without_confirmation(): void
    {
        $this->postJson('/api/v1/people', $this->payload())
            ->assertCreated();
    }

    public function test_the_citizen_card_is_unique_when_declared(): void
    {
        Person::factory()->create(['citizen_card_id' => 'FUC-2020-000123']);

        // A different name/birth date on purpose: this suite exercises
        // the citizen-card rule, not the homonym warning.
        $this->postJson('/api/v1/people', $this->payload([
            'identity_number' => '72090312340',
            'first_name' => 'Roberto',
            'birth_date' => '1972-09-03',
            'citizen_card_id' => 'FUC-2020-000123',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['citizen_card_id']);
    }

    public function test_the_citizen_card_is_optional(): void
    {
        Person::factory()->create(['citizen_card_id' => null]);

        $this->postJson('/api/v1/people', $this->payload([
            'identity_number' => '72090312340',
            'first_name' => 'Roberto',
            'birth_date' => '1972-09-03',
            'citizen_card_id' => null,
        ]))->assertCreated();
    }

    public function test_database_backstop_rejects_a_duplicate_identity(): void
    {
        // UNIQUE identity_number: the service probes first; the index
        // is the last line of defense (RN-008 convention).
        $this->expectException(QueryException::class);

        Person::query()->create($this->payload());
        Person::query()->create($this->payload(['identity_number' => '85061510002']));
    }
}
