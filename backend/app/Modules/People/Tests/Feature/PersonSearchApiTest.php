<?php

declare(strict_types=1);

namespace App\Modules\People\Tests\Feature;

use App\Modules\People\Infrastructure\Persistence\Models\Person;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Person search GET /api/v1/people (RF-PER-004): exact identity,
 * name combinations, basic filters, pagination and the fields that
 * disambiguate homonyms (birth date and parents).
 */
final class PersonSearchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsRole('admin');

        $this->seedPeople();
    }

    private function seedPeople(): void
    {
        Person::factory()->create([
            'identity_number' => PersonFactory::identity('M', '1980-01-05'),
            'first_name' => 'Juan',
            'first_surname' => 'Pérez',
            'second_surname' => 'Gómez',
            'sex' => 'M',
            'birth_date' => '1980-01-05',
            'father_name' => 'Pedro Pérez',
            'mother_name' => 'Ana Gómez',
        ]);

        Person::factory()->create([
            'identity_number' => PersonFactory::identity('F', '1983-07-20'),
            'first_name' => 'Juanita',
            'first_surname' => 'Pérez',
            'second_surname' => null,
            'sex' => 'F',
            'birth_date' => '1983-07-20',
            'father_name' => 'Pedro Pérez',
            'mother_name' => 'Rosa Díaz',
        ]);

        Person::factory()->create([
            'identity_number' => PersonFactory::identity('M', '1990-03-12'),
            'first_name' => 'Luis',
            'first_surname' => 'Fernández',
            'second_surname' => null,
            'sex' => 'M',
            'birth_date' => '1990-03-12',
            'father_name' => 'Ramón Fernández',
            'mother_name' => 'Elena Cruz',
            'death_date' => '2023-11-30',
        ]);

        Person::factory()->create([
            'identity_number' => PersonFactory::identity('F', '1975-11-02'),
            'first_name' => 'María',
            'first_surname' => 'Álvarez',
            'second_surname' => 'Pérez',
            'sex' => 'F',
            'birth_date' => '1975-11-02',
            'father_name' => null,
            'mother_name' => null,
        ]);
    }

    public function test_searches_by_exact_identity(): void
    {
        $juan = Person::query()->where('first_name', 'Juan')->first();
        $this->assertNotNull($juan);

        $this->getJson("/api/v1/people?identity={$juan->identity_number}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $juan->id);
    }

    public function test_exact_identity_with_no_match_returns_an_empty_page(): void
    {
        $this->getJson('/api/v1/people?identity=00000000000')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_searches_by_name_fragment(): void
    {
        $this->getJson('/api/v1/people?q=Pérez')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_searches_by_partial_name_fragment(): void
    {
        $this->getJson('/api/v1/people?q=Fernánd')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Luis');
    }

    public function test_searches_by_combined_names_and_surnames(): void
    {
        // "combinación de nombres/apellidos": a single query term must
        // cross the column boundaries (first name + surname together).
        $this->getJson('/api/v1/people?q=Juan Pérez')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_filters_by_sex(): void
    {
        $this->getJson('/api/v1/people?sex=F')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/people?sex=M')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_rejects_an_unknown_sex_filter(): void
    {
        $this->getJson('/api/v1/people?sex=X')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sex']);
    }

    public function test_filters_by_birth_date_range(): void
    {
        $this->getJson('/api/v1/people?birth_from=1976-01-01&birth_to=1989-12-31')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/people?birth_from=1990-01-01')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Luis');
    }

    public function test_filters_by_deceased_state(): void
    {
        $this->getJson('/api/v1/people?deceased=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Luis');

        $this->getJson('/api/v1/people?deceased=false')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_rejects_an_invalid_deceased_filter(): void
    {
        $this->getJson('/api/v1/people?deceased=maybe')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deceased']);
    }

    public function test_paginates_results(): void
    {
        $this->getJson('/api/v1/people?per_page=2&page=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/v1/people?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_results_carry_the_disambiguation_fields(): void
    {
        // RF-PER-004: birth date and parents disambiguate homonyms.
        $this->getJson('/api/v1/people?q=Juan Pérez')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'identity_number',
                        'first_name',
                        'first_surname',
                        'birth_date',
                        'father_name',
                        'mother_name',
                        'sex',
                        'deceased',
                        'death_date',
                    ],
                ],
            ]);
    }

    public function test_orders_by_surname_name_and_birth_date(): void
    {
        // idx_people_names: deterministic listing order.
        $response = $this->getJson('/api/v1/people');

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $response->json('data');

        $names = collect($rows)
            ->map(fn (array $row): array => [$row['first_surname'], $row['first_name'], $row['birth_date']])
            ->all();

        $expected = [
            ['Álvarez', 'María', '1975-11-02'],
            ['Fernández', 'Luis', '1990-03-12'],
            ['Pérez', 'Juan', '1980-01-05'],
            ['Pérez', 'Juanita', '1983-07-20'],
        ];

        $this->assertSame($expected, $names);
    }

    public function test_excludes_soft_deleted_people(): void
    {
        $luis = Person::query()->where('first_name', 'Luis')->first();
        $this->assertNotNull($luis);
        $luis->delete();

        $this->getJson('/api/v1/people?q=Fernández')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/people')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    public function test_requires_authentication(): void
    {
        auth()->logout();

        $this->getJson('/api/v1/people')->assertUnauthorized();
    }
}
