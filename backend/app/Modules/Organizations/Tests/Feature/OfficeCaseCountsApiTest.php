<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Tests\Feature;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\EducationalLevel;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\EntityType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Municipality;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\OccupationalCategory;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\OfficeType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Organization;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\PensionRegime;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\PensionType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Position;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Province;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\ScientificCategory;
use App\Modules\Organizations\Infrastructure\Persistence\Models\Entity;
use App\Modules\Organizations\Infrastructure\Persistence\Models\Office;
use App\Modules\PensionCases\Domain\CaseStatus;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\PensionCase;
use App\Modules\People\Infrastructure\Persistence\Models\Person;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Case counts per office (RF-ENT-005 second part, ADR-28): the tree
 * and the detail expose how many pension cases each office has
 * handled — `cases_count` for the office itself and
 * `scope_cases_count` for its ámbito (itself + active descendant
 * offices). Every status counts (a case is being tramitado from the
 * moment it is captured), soft-deleted cases stop counting and the
 * subtree of a deactivated office leaves every scope — the
 * organizational map only contains active offices.
 *
 * The projection crosses a module boundary through the
 * Organizations port implemented by PensionCases
 * (OfficeCaseCountQueryInterface, dependency inversion per deptrac:
 * PensionCases may import Organizations, never the other way).
 */
final class OfficeCaseCountsApiTest extends TestCase
{
    use RefreshDatabase;

    private Office $root;

    private Office $provincial;

    private Office $municipal;

    /** @var array<string, int> */
    private array $refs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsRole('admin');

        $province = Province::query()->create(['code' => '12', 'name' => 'Holguín']);
        $municipality = Municipality::query()->create([
            'province_id' => $province->id, 'code' => '01', 'name' => 'Holguín',
        ]);
        $national = OfficeType::query()->create(['code' => 'NAC', 'name' => 'Nacional']);
        $provincialType = OfficeType::query()->create(['code' => 'PRO', 'name' => 'Provincial']);
        $municipalType = OfficeType::query()->create(['code' => 'MUN', 'name' => 'Municipal']);

        $this->root = Office::query()->create([
            'office_type_id' => $national->id,
            'province_id' => $province->id, 'municipality_id' => $municipality->id,
            'address' => 'Calle Martí #100, Holguín',
        ]);
        $this->provincial = Office::query()->create([
            'office_type_id' => $provincialType->id,
            'province_id' => $province->id, 'municipality_id' => $municipality->id,
            'address' => 'Calle Frexes #201, Holguín',
            'parent_office_id' => $this->root->id,
        ]);
        $this->municipal = Office::query()->create([
            'office_type_id' => $municipalType->id,
            'province_id' => $province->id, 'municipality_id' => $municipality->id,
            'address' => 'Calle Maceo #15, Holguín',
            'parent_office_id' => $this->provincial->id,
        ]);

        $organization = Organization::query()->create(['code' => 'MTSS', 'name' => 'Ministerio de Trabajo']);
        $entityType = EntityType::query()->create(['code' => 'EMP', 'name' => 'Empresa']);

        $this->refs = [
            'employer_entity_id' => Entity::query()->create([
                'code' => 'ENT-01',
                'tax_id_number' => '11000012345',
                'organization_id' => $organization->id,
                'province_id' => $province->id,
                'municipality_id' => $municipality->id,
                'entity_type_id' => $entityType->id,
                'address' => 'Calle 100 #0',
                'social_purpose' => 'Servicios técnicos',
            ])->id,
            'position_id' => Position::query()->create(['name' => 'Técnico', 'description' => 'Técnico medio'])->id,
            'occupational_category_id' => OccupationalCategory::query()->create(['code' => 'TC', 'name' => 'Técnico'])->id,
            'educational_level_id' => EducationalLevel::query()->create(['name' => 'Medio superior', 'description' => 'Bachiller'])->id,
            'scientific_category_id' => ScientificCategory::query()->create(['code' => 'NIN', 'name' => 'Ninguna'])->id,
            'pension_type_id' => PensionType::query()->create(['code' => 'VEJ', 'name' => 'Vejez'])->id,
            'pension_regime_id' => PensionRegime::query()->create([
                'name' => 'Seguro social',
                'description' => 'Régimen general',
                'months_per_year' => 12,
            ])->id,
        ];
    }

    /**
     * Fabricates a case handled by $office (fixture numbers outside
     * the sequence domain, one living applicant per case so the
     * physical one-open-case-per-person constraint never collides).
     */
    private function seedCase(Office $office, string $number, CaseStatus $status = CaseStatus::Submitted): PensionCase
    {
        return PensionCase::query()->create([
            'number' => $number,
            'requested_at' => now()->toDateString(),
            'status' => $status->value,
            'applicant_person_id' => Person::factory()->create([
                'identity_number' => PersonFactory::identity('M', '1962-03-10'),
                'birth_date' => '1962-03-10',
                'sex' => 'M',
            ])->id,
            'office_id' => $office->id,
            'employer_entity_id' => $this->refs['employer_entity_id'],
            'position_id' => $this->refs['position_id'],
            'occupational_category_id' => $this->refs['occupational_category_id'],
            'educational_level_id' => $this->refs['educational_level_id'],
            'scientific_category_id' => $this->refs['scientific_category_id'],
            'pension_type_id' => $this->refs['pension_type_id'],
            'pension_regime_id' => $this->refs['pension_regime_id'],
            'rebel_army_member' => false,
            'last_salary' => '5000.00',
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function treeOffice(int $id): ?array
    {
        $nodes = $this->getJson('/api/v1/offices/tree')->assertOk()->json('data');

        return $this->findNode($nodes ?? [], $id);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, mixed>|null
     */
    private function findNode(array $nodes, int $id): ?array
    {
        foreach ($nodes as $node) {
            if ((int) $node['id'] === $id) {
                return $node;
            }
            $nested = $this->findNode($node['children'] ?? [], $id);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    public function test_the_tree_counts_cases_per_office_and_scope(): void
    {
        $this->seedCase($this->root, '900001');
        $this->seedCase($this->provincial, '900002');
        $this->seedCase($this->provincial, '900003');
        $this->seedCase($this->municipal, '900004');

        $root = $this->treeOffice($this->root->id);
        $provincial = $this->treeOffice($this->provincial->id);
        $municipal = $this->treeOffice($this->municipal->id);

        $this->assertNotNull($root);
        $this->assertNotNull($provincial);
        $this->assertNotNull($municipal);

        $this->assertSame(1, $root['cases_count']);
        $this->assertSame(4, $root['scope_cases_count']);

        $this->assertSame(2, $provincial['cases_count']);
        $this->assertSame(3, $provincial['scope_cases_count']);

        $this->assertSame(1, $municipal['cases_count']);
        $this->assertSame(1, $municipal['scope_cases_count']);
    }

    public function test_the_detail_counts_cases_of_the_office_and_its_scope(): void
    {
        $this->seedCase($this->root, '900010');
        $this->seedCase($this->municipal, '900011');

        $this->getJson("/api/v1/offices/{$this->root->id}")
            ->assertOk()
            ->assertJsonPath('data.cases_count', 1)
            ->assertJsonPath('data.scope_cases_count', 2);

        $this->getJson("/api/v1/offices/{$this->municipal->id}")
            ->assertOk()
            ->assertJsonPath('data.cases_count', 1)
            ->assertJsonPath('data.scope_cases_count', 1);
    }

    public function test_offices_without_cases_answer_zero(): void
    {
        $this->getJson("/api/v1/offices/{$this->root->id}")
            ->assertOk()
            ->assertJsonPath('data.cases_count', 0)
            ->assertJsonPath('data.scope_cases_count', 0);

        $node = $this->treeOffice($this->root->id);
        $this->assertNotNull($node);
        $this->assertSame(0, $node['cases_count']);
        $this->assertSame(0, $node['scope_cases_count']);
    }

    public function test_every_status_counts_as_tramitado(): void
    {
        $this->seedCase($this->municipal, '900020', CaseStatus::Submitted);
        $this->seedCase($this->municipal, '900021', CaseStatus::UnderReview);
        $this->seedCase($this->municipal, '900022', CaseStatus::Approved);
        $this->seedCase($this->municipal, '900023', CaseStatus::Rejected);

        $this->getJson("/api/v1/offices/{$this->municipal->id}")
            ->assertOk()
            ->assertJsonPath('data.cases_count', 4);
    }

    public function test_soft_deleted_cases_stop_counting(): void
    {
        $case = $this->seedCase($this->municipal, '900030');
        $this->seedCase($this->municipal, '900031');

        $case->delete();

        $this->getJson("/api/v1/offices/{$this->municipal->id}")
            ->assertOk()
            ->assertJsonPath('data.cases_count', 1)
            ->assertJsonPath('data.scope_cases_count', 1);
    }

    public function test_a_deactivated_subtree_leaves_every_scope(): void
    {
        $this->seedCase($this->root, '900040');
        $case = $this->seedCase($this->municipal, '900041');

        // Deactivate bottom-up: the guard refuses while active
        // children hang from the node being deactivated.
        $this->municipal->delete();

        $this->getJson("/api/v1/offices/{$this->root->id}")
            ->assertOk()
            ->assertJsonPath('data.cases_count', 1)
            ->assertJsonPath('data.scope_cases_count', 1);

        // The case row survives (FK restrict) and still answers to its
        // own office — but that office is no longer part of the map.
        $this->assertDatabaseHas('pension_cases', ['id' => $case->id]);
        $this->getJson("/api/v1/offices/{$this->municipal->id}")->assertNotFound();

        $node = $this->treeOffice($this->root->id);
        $this->assertNotNull($node);
        $this->assertSame(1, $node['scope_cases_count']);
    }

    public function test_the_count_surface_answers_to_organizations_view(): void
    {
        $this->seedCase($this->municipal, '900050');
        auth()->logout();

        $this->getJson('/api/v1/offices/tree')->assertUnauthorized();
        $this->getJson("/api/v1/offices/{$this->root->id}")->assertUnauthorized();

        $this->actingAsRole('operator');

        $this->getJson("/api/v1/offices/{$this->root->id}")
            ->assertOk()
            ->assertJsonPath('data.scope_cases_count', 1);
    }
}
