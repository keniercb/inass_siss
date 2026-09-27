<?php

declare(strict_types=1);

namespace App\Modules\LegalBasis\Tests\Feature;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\LegalBasisType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Organization;
use App\Modules\LegalBasis\Infrastructure\Persistence\Models\LegalBasis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Role enforcement over /api/v1/legal-bases (RF-SEG-002, ADR-22):
 * reads answer to legalbases.view — held by every consultation role —
 * while writes answer to legalbases.manage, exclusive to admin in
 * the PermissionMatrix.
 */
final class RbacLegalBasisApiTest extends TestCase
{
    use RefreshDatabase;

    private LegalBasis $basis;

    private LegalBasisType $type;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->type = LegalBasisType::query()->create(['code' => 'LEY', 'name' => 'Ley']);
        $this->organization = Organization::query()->create(['code' => 'MTSS', 'name' => 'Ministerio de Trabajo']);

        $this->basis = LegalBasis::query()->create([
            'legal_basis_type_id' => $this->type->id,
            'number' => '128',
            'issue_date' => '2019-07-16',
            'effective_date' => '2019-08-01',
            'issuing_organization_id' => $this->organization->id,
            'year' => 2019,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function storePayload(): array
    {
        return [
            'legal_basis_type_id' => $this->type->id,
            'number' => '129',
            'issue_date' => '2019-07-17',
            'effective_date' => '2019-08-02',
            'issuing_organization_id' => $this->organization->id,
        ];
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: int}> */
    public static function roleMatrixProvider(): array
    {
        // [role, verb, path, expected status]. The expectations mirror
        // the PermissionMatrix grants for legalbases.* exactly.
        return [
            'admin lists legal bases' => ['admin', 'GET', '/api/v1/legal-bases', 200],
            'admin stores legal bases' => ['admin', 'POST', '/api/v1/legal-bases', 201],
            'admin edits legal bases' => ['admin', 'PATCH', '/api/v1/legal-bases/{id}', 200],
            'admin deletes legal bases' => ['admin', 'DELETE', '/api/v1/legal-bases/{id}', 200],

            'director lists legal bases' => ['director', 'GET', '/api/v1/legal-bases', 200],
            'director cannot store legal bases' => ['director', 'POST', '/api/v1/legal-bases', 403],
            'director cannot edit legal bases' => ['director', 'PATCH', '/api/v1/legal-bases/{id}', 403],

            'specialist lists legal bases' => ['specialist', 'GET', '/api/v1/legal-bases', 200],
            'specialist cannot store legal bases' => ['specialist', 'POST', '/api/v1/legal-bases', 403],

            'operator lists legal bases' => ['operator', 'GET', '/api/v1/legal-bases', 200],
            'operator cannot delete legal bases' => ['operator', 'DELETE', '/api/v1/legal-bases/{id}', 403],

            'auditor lists legal bases' => ['auditor', 'GET', '/api/v1/legal-bases', 200],
            'auditor cannot edit legal bases' => ['auditor', 'PATCH', '/api/v1/legal-bases/{id}', 403],
            'auditor cannot store legal bases' => ['auditor', 'POST', '/api/v1/legal-bases', 403],
        ];
    }

    #[DataProvider('roleMatrixProvider')]
    public function test_roles_answer_the_legal_basis_permission_matrix(string $role, string $verb, string $path, int $status): void
    {
        $this->actingAsRole($role);

        $path = str_replace('{id}', (string) $this->basis->id, $path);

        $payload = match ($verb) {
            'POST' => $this->storePayload(),
            'PATCH' => ['reference' => 'Corrección de referencia'],
            default => [],
        };

        $this->json($verb, $path, $payload)->assertStatus($status);
    }
}
