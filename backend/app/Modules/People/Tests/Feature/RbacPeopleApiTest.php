<?php

declare(strict_types=1);

namespace App\Modules\People\Tests\Feature;

use App\Modules\People\Infrastructure\Persistence\Models\Person;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Role enforcement over /api/v1/people (RF-SEG-002, S3.4): every
 * route answers to a people.* permission seeded from the
 * PermissionMatrix, so the expected status below is derived from the
 * matrix itself — operador registers and edits people but cannot
 * delete them, and auditor is strictly read-only.
 */
final class RbacPeopleApiTest extends TestCase
{
    use RefreshDatabase;

    private Person $person;

    protected function setUp(): void
    {
        parent::setUp();

        $this->person = Person::factory()->create();
    }

    /** @return array<string, mixed> */
    private function storePayload(): array
    {
        return [
            'identity_number' => PersonFactory::identity('F', '1992-04-18'),
            'first_name' => 'Rosa',
            'first_surname' => 'Díaz',
            'sex' => 'F',
            'address' => 'Calle L #5, Vedado, La Habana',
            'birth_date' => '1992-04-18',
        ];
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: int}> */
    public static function roleMatrixProvider(): array
    {
        // [role, verb, path, expected status]. The expectations mirror
        // the PermissionMatrix grants for people.* exactly.
        return [
            'admin lists people' => ['admin', 'GET', '/api/v1/people', 200],
            'admin stores people' => ['admin', 'POST', '/api/v1/people', 201],
            'admin edits people' => ['admin', 'PATCH', '/api/v1/people/{id}', 200],
            'admin registers death' => ['admin', 'POST', '/api/v1/people/{id}/death', 200],
            'admin deletes people' => ['admin', 'DELETE', '/api/v1/people/{id}', 200],

            'director lists people' => ['director', 'GET', '/api/v1/people', 200],
            'director cannot store people' => ['director', 'POST', '/api/v1/people', 403],

            'specialist lists people' => ['specialist', 'GET', '/api/v1/people', 200],
            'specialist cannot store people' => ['specialist', 'POST', '/api/v1/people', 403],
            'specialist cannot edit people' => ['specialist', 'PATCH', '/api/v1/people/{id}', 403],

            'operator lists people' => ['operator', 'GET', '/api/v1/people', 200],
            'operator stores people' => ['operator', 'POST', '/api/v1/people', 201],
            'operator edits people' => ['operator', 'PATCH', '/api/v1/people/{id}', 200],
            'operator registers death' => ['operator', 'POST', '/api/v1/people/{id}/death', 200],
            'operator cannot delete people' => ['operator', 'DELETE', '/api/v1/people/{id}', 403],

            'auditor lists people' => ['auditor', 'GET', '/api/v1/people', 200],
            'auditor cannot store people' => ['auditor', 'POST', '/api/v1/people', 403],
            'auditor cannot edit people' => ['auditor', 'PATCH', '/api/v1/people/{id}', 403],
            'auditor cannot register death' => ['auditor', 'POST', '/api/v1/people/{id}/death', 403],
            'auditor cannot delete people' => ['auditor', 'DELETE', '/api/v1/people/{id}', 403],
        ];
    }

    #[DataProvider('roleMatrixProvider')]
    public function test_roles_answer_the_people_permission_matrix(string $role, string $verb, string $path, int $status): void
    {
        $this->actingAsRole($role);

        $path = str_replace('{id}', (string) $this->person->id, $path);

        $payload = match ($verb) {
            'POST' => str_contains($path, '/death')
                ? ['death_date' => '2024-01-15']
                : $this->storePayload(),
            'PATCH' => ['address' => 'Calle 8 #100, Playa, La Habana'],
            default => [],
        };

        $this->json($verb, $path, $payload)->assertStatus($status);
    }
}
