<?php

declare(strict_types=1);

namespace App\Modules\People\Tests\Feature;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\Race;
use App\Modules\People\Infrastructure\Persistence\Models\Person;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Person registration and edition /api/v1/people (RF-PER-001,
 * RF-PER-002, RN-001): mandatory fields, the Cuban identity validator
 * as a request rule (11 digits, month/day ranges and the digit-10 sex
 * parity cross-checked against the declared sex), immutable identity
 * after creation, soft delete and the audited trail with the previous
 * values.
 */
final class PersonCrudApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Admin: full people.* permissions, so the CRUD suite exercises
        // the happy path; role-specific denial lives in RbacPeopleApiTest.
        $this->user = $this->actingAsRole('admin');
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
            'middle_name' => 'Carlos',
            'first_surname' => 'Pérez',
            'second_surname' => 'Gómez',
            'sex' => 'M',
            'address' => 'Calle 23 #45 e/ 10 y 12, Vedado, La Habana',
            'birth_date' => '1985-06-15',
            'father_name' => 'Pedro Pérez Rodríguez',
            'mother_name' => 'María Gómez Fernández',
        ], $overrides);
    }

    public function test_requires_authentication(): void
    {
        auth()->logout();

        $this->postJson('/api/v1/people', $this->payload())
            ->assertUnauthorized();
    }

    public function test_registers_a_person_with_authorship(): void
    {
        $response = $this->postJson('/api/v1/people', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.identity_number', '85061510002')
            ->assertJsonPath('data.first_name', 'Juan')
            ->assertJsonPath('data.first_surname', 'Pérez')
            ->assertJsonPath('data.sex', 'M')
            ->assertJsonPath('data.birth_date', '1985-06-15')
            ->assertJsonPath('data.deceased', false)
            ->assertJsonPath('data.death_date', null);

        $this->assertDatabaseHas('people', [
            'identity_number' => '85061510002',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_registration_lands_in_the_audit_trail(): void
    {
        $this->postJson('/api/v1/people', $this->payload())->assertCreated();

        $this->assertDatabaseHas('activity_log', [
            'event' => 'created',
            'causer_id' => $this->user->id,
            'subject_type' => Person::class,
        ]);
    }

    #[DataProvider('invalidIdentityProvider')]
    public function test_rejects_structurally_invalid_identity_numbers(string $identity, string $message): void
    {
        $this->postJson('/api/v1/people', $this->payload(['identity_number' => $identity]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['identity_number']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function invalidIdentityProvider(): array
    {
        return [
            'too short' => ['8506151002', '10 digits'],
            'too long' => ['850615100023', '12 digits'],
            'letters' => ['8506151000a', 'non-numeric'],
            'impossible month' => ['85133112345', 'month 13 cannot exist'],
            'month zero' => ['85003112345', 'month 00 cannot exist'],
            'day 32' => ['85063212345', 'no month has 32 days'],
            'day zero' => ['85060012345', 'day 00 cannot exist'],
            'empty' => ['', 'required'],
        ];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function invalidMandatoryProvider(): array
    {
        return [
            'first_name' => ['first_name', ''],
            'first_surname' => ['first_surname', ''],
            'sex' => ['sex', 'X'],
            'sex lowercase' => ['sex', 'm'],
            'birth_date' => ['birth_date', ''],
            'birth_date format' => ['birth_date', '15-06-1985'],
            'address' => ['address', ''],
        ];
    }

    public function test_accepts_identity_numbers_with_any_leading_digits(): void
    {
        // The two leading digits are the birth year and stay
        // unvalidated: numbers that the old century-prefix rule
        // rejected (anything not starting with 1-6) now pass as
        // long as the month/day ranges and the digit-10 sex parity
        // hold.
        $this->postJson('/api/v1/people', $this->payload(['identity_number' => '98061510001']))
            ->assertCreated()
            ->assertJsonPath('data.identity_number', '98061510001');
    }

    public function test_rejects_a_sex_that_does_not_match_the_identity_number(): void
    {
        // 85061510002 encodes a male (digit 10 is even); the
        // declared sex contradicts it.
        $this->postJson('/api/v1/people', $this->payload(['sex' => 'F']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['identity_number']);
    }

    #[DataProvider('invalidMandatoryProvider')]
    public function test_rejects_invalid_mandatory_fields(string $field, string $value): void
    {
        $this->postJson('/api/v1/people', $this->payload([$field => $value]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    public function test_rejects_a_future_birth_date(): void
    {
        $this->postJson('/api/v1/people', $this->payload(['birth_date' => '2100-01-01']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['birth_date']);
    }

    public function test_rejects_names_over_the_limit(): void
    {
        $this->postJson('/api/v1/people', $this->payload([
            'first_name' => str_repeat('J', 51),
            'father_name' => str_repeat('P', 121),
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'father_name']);
    }

    public function test_rejects_a_unknown_race(): void
    {
        $this->postJson('/api/v1/people', $this->payload(['race_id' => 999999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['race_id']);
    }

    public function test_accepts_an_existing_race(): void
    {
        $race = Race::query()
            ->create(['name' => 'Blanca']);

        $this->postJson('/api/v1/people', $this->payload(['race_id' => $race->id]))
            ->assertCreated()
            ->assertJsonPath('data.race_id', $race->id);
    }

    public function test_database_backstop_rejects_an_invalid_sex(): void
    {
        // CHECK chk_people_sex: the request layer validates first; the
        // constraint is the last line of defense (RN-008 convention).
        $this->expectException(QueryException::class);

        Person::query()->create($this->payload(['sex' => 'X']));
    }

    public function test_shows_a_person(): void
    {
        $person = Person::factory()->create(['first_name' => 'Ana']);

        $this->getJson("/api/v1/people/{$person->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $person->id)
            ->assertJsonPath('data.first_name', 'Ana')
            ->assertJsonPath('data.identity_number', $person->identity_number);
    }

    public function test_show_returns_404_for_an_unknown_person(): void
    {
        $this->getJson('/api/v1/people/999999')->assertNotFound();
    }

    public function test_updates_a_person_and_audits_previous_values(): void
    {
        $person = Person::factory()->create(['address' => 'Calle 100, Marianao']);

        $this->patchJson("/api/v1/people/{$person->id}", [
            'address' => 'Calle 5 #12, Vedado, La Habana',
            'mother_name' => 'María Gómez Fernández',
        ])->assertOk()
            ->assertJsonPath('data.address', 'Calle 5 #12, Vedado, La Habana');

        $this->assertDatabaseHas('people', [
            'id' => $person->id,
            'address' => 'Calle 5 #12, Vedado, La Habana',
            'updated_by' => $this->user->id,
        ]);

        // RF-PER-002: the trail keeps the previous and new values.
        $this->assertDatabaseHas('activity_log', [
            'event' => 'updated',
            'subject_type' => Person::class,
            'subject_id' => $person->id,
            'causer_id' => $this->user->id,
        ]);

        $entry = Activity::query()
            ->where('subject_type', Person::class)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('Calle 100, Marianao', $entry->properties['old']['address'] ?? null);
        $this->assertSame('Calle 5 #12, Vedado, La Habana', $entry->properties['attributes']['address'] ?? null);
    }

    public function test_update_rejects_an_identity_number_change(): void
    {
        // RN-001: the identity is immutable after creation.
        $person = Person::factory()->create();

        $this->patchJson("/api/v1/people/{$person->id}", [
            'identity_number' => '95061510002',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['identity_number']);

        $this->assertDatabaseHas('people', [
            'id' => $person->id,
            'identity_number' => $person->identity_number,
        ]);
    }

    public function test_update_rejects_the_death_date_field(): void
    {
        // RF-PER-003: death is a lifecycle action with its own audited
        // endpoint, never a plain field edit.
        $person = Person::factory()->create();

        $this->patchJson("/api/v1/people/{$person->id}", [
            'death_date' => '2024-03-10',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['death_date']);
    }

    public function test_update_rejects_a_sex_that_contradicts_the_identity_number(): void
    {
        // The identity number is immutable and its digit 10 encodes
        // the sex (even male): a PATCH may restate the same sex, but
        // never contradict the number.
        $person = Person::factory()->create(); // male factory identity

        $this->patchJson("/api/v1/people/{$person->id}", ['sex' => 'F'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sex']);

        $this->assertDatabaseHas('people', [
            'id' => $person->id,
            'sex' => 'M',
        ]);
    }

    public function test_update_allows_restating_the_sex_encoded_by_the_identity_number(): void
    {
        $person = Person::factory()->create();

        $this->patchJson("/api/v1/people/{$person->id}", ['sex' => 'M'])
            ->assertOk()
            ->assertJsonPath('data.sex', 'M');
    }

    public function test_update_returns_404_for_an_unknown_person(): void
    {
        $this->patchJson('/api/v1/people/999999', ['address' => 'Calle 8'])
            ->assertNotFound();
    }

    public function test_soft_deletes_a_person(): void
    {
        $person = Person::factory()->create();

        $this->deleteJson("/api/v1/people/{$person->id}")->assertOk();

        $this->assertSoftDeleted('people', ['id' => $person->id]);
        $this->getJson("/api/v1/people/{$person->id}")->assertNotFound();

        $this->assertDatabaseHas('activity_log', [
            'event' => 'deleted',
            'subject_type' => Person::class,
            'subject_id' => $person->id,
        ]);
    }

    public function test_destroy_returns_404_for_an_unknown_person(): void
    {
        $this->deleteJson('/api/v1/people/999999')->assertNotFound();
    }
}
