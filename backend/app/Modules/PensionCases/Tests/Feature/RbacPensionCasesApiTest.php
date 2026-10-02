<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Tests\Feature;

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
use App\Modules\PensionCases\Tests\Concerns\ResetsCaseSequence;
use App\Modules\People\Infrastructure\Persistence\Models\Person;
use Database\Factories\PersonFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Role enforcement over /api/v1/pension-cases (RF-SEG-002, S5):
 * reads answer to cases.view — held by every consultation role,
 * including the Auditor to cross-read the subjects of the bitácora —
 * while creation answers to cases.create (operator and admin: data
 * capture, section 2.2) and the subrecord highs/removals answer to
 * cases.edit.
 */
final class RbacPensionCasesApiTest extends TestCase
{
    use RefreshDatabase, ResetsCaseSequence;

    private PensionCase $case;

    protected function setUp(): void
    {
        parent::setUp();

        // Boots the RBAC tables without a particular actor and
        // declares the `pension_case` sequence scope (ADR-17).
        $this->seed(SettingsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->case = $this->seedCase();
    }

    private function seedCase(): PensionCase
    {
        $province = Province::query()->create(['code' => '11', 'name' => 'La Habana']);
        $municipality = Municipality::query()->create(['province_id' => $province->id, 'code' => '03', 'name' => 'Playa']);
        $officeType = OfficeType::query()->create(['code' => 'MUN', 'name' => 'Municipal']);
        $organization = Organization::query()->create(['code' => 'MTSS', 'name' => 'Ministerio de Trabajo']);
        $entityType = EntityType::query()->create(['code' => 'EMP', 'name' => 'Empresa']);

        $applicant = Person::factory()->create([
            'identity_number' => PersonFactory::identity('M', '1962-03-10'),
            'birth_date' => '1962-03-10',
            'sex' => 'M',
        ]);

        $office = Office::query()->create([
            'office_type_id' => $officeType->id,
            'province_id' => $province->id,
            'municipality_id' => $municipality->id,
            'address' => 'Calle 42 #7, Playa',
        ]);

        $entity = Entity::query()->create([
            'code' => 'ENT-01',
            'name' => 'Servicios Técnicos',
            'tax_id_number' => '11000012345',
            'organization_id' => $organization->id,
            'province_id' => $province->id,
            'municipality_id' => $municipality->id,
            'entity_type_id' => $entityType->id,
            'address' => 'Calle 100 #0',
            'social_purpose' => 'Servicios técnicos',
        ]);

        return PensionCase::query()->create([
            // Fixture number outside the sequence domain: the writer
            // role tests create through the API and the sequence
            // starts at 1, so a colliding fixture would break the
            // physical UNIQUE.
            'number' => '11-2026-90001',
            'requested_at' => now()->toDateString(),
            'status' => CaseStatus::Submitted->value,
            'applicant_person_id' => $applicant->id,
            'office_id' => $office->id,
            'employer_entity_id' => $entity->id,
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
            'rebel_army_member' => false,
            'last_salary' => '5000.00',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function storePayload(): array
    {
        $person = Person::factory()->create();
        $case = $this->case;

        // Rule 0 (ADR-33): the payload carries NO office_id — the
        // case assumes the office of the authenticated user. The
        // writer roles get the fixture office assigned in the test.
        return [
            'applicant_person_id' => $person->id,
            'employer_entity_id' => $case->employer_entity_id,
            'position_id' => $case->position_id,
            'occupational_category_id' => $case->occupational_category_id,
            'educational_level_id' => $case->educational_level_id,
            'scientific_category_id' => $case->scientific_category_id,
            'pension_type_id' => $case->pension_type_id,
            'pension_regime_id' => $case->pension_regime_id,
            'rebel_army_member' => false,
            // Task 37: promovente classification flag — required at
            // the wire exactly like rebel_army_member.
            'internationalist' => false,
            'last_salary' => '5000.00',
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function consultationRoles(): array
    {
        return [
            'admin' => ['admin'],
            'director' => ['director'],
            'specialist' => ['specialist'],
            'operator' => ['operator'],
            'auditor' => ['auditor'],
        ];
    }

    #[DataProvider('consultationRoles')]
    public function test_every_role_reads_the_case(string $role): void
    {
        $this->actingAsRole($role);

        $this->getJson('/api/v1/pension-cases')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/pension-cases/{$this->case->id}")
            ->assertOk()
            ->assertJsonPath('data.number', '11-2026-90001');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function writerRoles(): array
    {
        return [
            'admin' => ['admin'],
            'operator' => ['operator'],
        ];
    }

    #[DataProvider('writerRoles')]
    public function test_the_capture_roles_create_and_edit(string $role): void
    {
        // Rule 0: the registering user needs an office for the case
        // to assume (ADR-33).
        $user = $this->actingAsRole($role);
        $user->forceFill(['office_id' => $this->case->office_id])->save();

        $this->postJson('/api/v1/pension-cases', $this->storePayload())
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.office_id', $this->case->office_id);

        $this->postJson("/api/v1/pension-cases/{$this->case->id}/salary-records", [
            'year' => 2023, 'earned_salary' => '4800.00',
        ])->assertStatus(201);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonWriterRoles(): array
    {
        return [
            'specialist' => ['specialist'],
            'director' => ['director'],
            'auditor' => ['auditor'],
        ];
    }

    #[DataProvider('nonWriterRoles')]
    public function test_consultation_roles_cannot_create_nor_edit(string $role): void
    {
        $this->actingAsRole($role);

        $this->postJson('/api/v1/pension-cases', $this->storePayload())
            ->assertForbidden();

        $this->postJson("/api/v1/pension-cases/{$this->case->id}/salary-records", [
            'year' => 2023, 'earned_salary' => '4800.00',
        ])->assertForbidden();

        $this->deleteJson("/api/v1/pension-cases/{$this->case->id}/salary-records/1")
            ->assertForbidden();
    }

    #[DataProvider('writerRoles')]
    public function test_the_capture_roles_update_and_delete_the_case(string $role): void
    {
        $this->actingAsRole($role);

        // SGP-34: the PUT edits the case while the promovente stays
        // immutable, and the DELETE soft-deletes a submitted case —
        // both answer cases.edit like the subrecord writes.
        $this->putJson("/api/v1/pension-cases/{$this->case->id}", [
            'last_salary' => '6200.00',
        ])->assertStatus(200)->assertJsonPath('data.last_salary', '6200.00');

        $this->deleteJson("/api/v1/pension-cases/{$this->case->id}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Case deleted.');
    }

    #[DataProvider('nonWriterRoles')]
    public function test_consultation_roles_cannot_update_nor_delete(string $role): void
    {
        $this->actingAsRole($role);

        $this->putJson("/api/v1/pension-cases/{$this->case->id}", [
            'last_salary' => '6200.00',
        ])->assertForbidden();

        $this->deleteJson("/api/v1/pension-cases/{$this->case->id}")->assertForbidden();
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/pension-cases')->assertUnauthorized();
        $this->getJson("/api/v1/pension-cases/{$this->case->id}")->assertUnauthorized();
        $this->postJson('/api/v1/pension-cases', $this->storePayload())->assertUnauthorized();
        $this->putJson("/api/v1/pension-cases/{$this->case->id}", ['last_salary' => '6200.00'])->assertUnauthorized();
        $this->deleteJson("/api/v1/pension-cases/{$this->case->id}")->assertUnauthorized();
    }
}
