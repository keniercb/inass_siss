<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Tests\Feature;

use App\Modules\Catalogs\Domain\PaymentForm;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Agency;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\AgencyType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\EducationalLevel;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\EntityType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\IncomeConcept;
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
use App\Modules\PensionCases\Tests\Concerns\ResetsCaseSequence;
use App\Modules\People\Infrastructure\Persistence\Models\Person;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Database\Factories\PersonFactory;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Applied percent of the declared income concepts (Task 42, user
 * correction, SGP-36, RF-EXP-002b): every declaration carries the
 * percent to apply — a Double in the user's words that the project's
 * RN-005 doctrine materializes as an EXACT decimal (DECIMAL(5,2),
 * never a binary float) — REQUIRED at both write paths (the nested
 * rows of the atomic creation and the individual endpoint), with
 * range 0-100 and at most two decimals: 422 when omitted, out of
 * range or carrying a third decimal.
 */
final class PensionCaseAppliedPercentApiTest extends TestCase
{
    use RefreshDatabase, ResetsCaseSequence;

    private Person $applicant;

    private Entity $entity;

    private Office $office;

    private User $operator;

    private int $positionId;

    private int $occupationalCategoryId;

    private int $educationalLevelId;

    private int $scientificCategoryId;

    private int $pensionTypeId;

    private int $pensionRegimeId;

    private int $incomeConceptId;

    private int $otherIncomeConceptId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);

        $province = Province::query()->create(['code' => '11', 'name' => 'La Habana']);
        $municipality = Municipality::query()->create(['province_id' => $province->id, 'code' => '03', 'name' => 'Playa']);
        $officeType = OfficeType::query()->create(['code' => 'MUN', 'name' => 'Municipal']);
        $organization = Organization::query()->create(['code' => 'MTSS', 'name' => 'Ministerio de Trabajo']);
        $entityType = EntityType::query()->create(['code' => 'EMP', 'name' => 'Empresa']);

        // The collection group rides the electronic payroll form so
        // the bank account stays optional and the payloads of this
        // suite stay focused on the applied percent.
        $payrollType = AgencyType::query()->create([
            'code' => 'NE',
            'name' => 'Agencia de nómina',
            'payment_form' => PaymentForm::NominaElectronica->value,
        ]);
        Agency::query()->create([
            'code' => 'BPA-NE-1',
            'name' => 'Agencia BPA nómina',
            'province_id' => $province->id,
            'municipality_id' => $municipality->id,
            'agency_type_id' => $payrollType->id,
        ]);

        $this->applicant = Person::factory()->create([
            'identity_number' => PersonFactory::identity('M', '1962-03-10'),
            'birth_date' => '1962-03-10',
            'sex' => 'M',
        ]);

        $this->office = Office::query()->create([
            'office_type_id' => $officeType->id,
            'province_id' => $province->id,
            'municipality_id' => $municipality->id,
            'address' => 'Calle 42 #7, Playa',
        ]);

        $this->entity = Entity::query()->create([
            'code' => 'ENT-42',
            'name' => 'Servicios Técnicos',
            'tax_id_number' => '11000012345',
            'organization_id' => $organization->id,
            'province_id' => $province->id,
            'municipality_id' => $municipality->id,
            'entity_type_id' => $entityType->id,
            'address' => 'Calle 100 #0',
            'social_purpose' => 'Servicios técnicos',
        ]);

        $this->positionId = Position::query()->create(['name' => 'Técnico', 'description' => 'Técnico medio'])->id;
        $this->occupationalCategoryId = OccupationalCategory::query()->create(['code' => 'TC', 'name' => 'Técnico'])->id;
        $this->educationalLevelId = EducationalLevel::query()->create(['name' => 'Medio superior', 'description' => 'Bachiller'])->id;
        $this->scientificCategoryId = ScientificCategory::query()->create(['code' => 'NIN', 'name' => 'Ninguna'])->id;
        $this->pensionTypeId = PensionType::query()->create(['code' => 'VEJ', 'name' => 'Vejez'])->id;
        $this->pensionRegimeId = PensionRegime::query()->create([
            'name' => 'Seguro social',
            'description' => 'Régimen general',
            'months_per_year' => 12,
        ])->id;
        $this->incomeConceptId = IncomeConcept::query()->create([
            'name' => 'Salario en divisas',
            'description' => 'Estimulación en divisas',
            'applies_base_salary' => false,
        ])->id;
        $this->otherIncomeConceptId = IncomeConcept::query()->create([
            'name' => 'Antigüedad',
            'description' => 'Pago por años de servicio',
            'applies_base_salary' => false,
        ])->id;

        $this->operator = $this->actingAsRole('operator');
        $this->operator->forceFill(['office_id' => $this->office->id])->save();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $province = Province::query()->where('code', '11')->firstOrFail();
        $municipality = Municipality::query()->where('code', '03')->firstOrFail();
        $payrollType = AgencyType::query()->where('code', 'NE')->firstOrFail();
        $agency = Agency::query()->where('code', 'BPA-NE-1')->firstOrFail();

        return array_merge([
            'applicant_person_id' => $this->applicant->id,
            'employer_entity_id' => $this->entity->id,
            'position_id' => $this->positionId,
            'occupational_category_id' => $this->occupationalCategoryId,
            'educational_level_id' => $this->educationalLevelId,
            'scientific_category_id' => $this->scientificCategoryId,
            'pension_type_id' => $this->pensionTypeId,
            'pension_regime_id' => $this->pensionRegimeId,
            'rebel_army_member' => false,
            'internationalist' => false,
            'last_salary' => '5000.00',
            'current_address' => 'Calle 8 #10, Playa',
            'residence_province_id' => $province->id,
            'residence_municipality_id' => $municipality->id,
            'collection_agency_type_id' => $payrollType->id,
            'collection_agency_id' => $agency->id,
        ], $overrides);
    }

    public function test_the_nested_creation_persists_the_applied_percent_of_every_row(): void
    {
        $response = $this->postJson('/api/v1/pension-cases', $this->payload([
            'income_concept_records' => [
                ['income_concept_id' => $this->incomeConceptId, 'amount' => '150.00', 'applied_percent' => '100'],
                ['income_concept_id' => $this->otherIncomeConceptId, 'amount' => '200.00', 'applied_percent' => '25.5'],
            ],
        ]));

        $response->assertStatus(201)
            ->assertJsonPath('data.income_concept_records.0.applied_percent', '100.00')
            ->assertJsonPath('data.income_concept_records.1.applied_percent', '25.50');
    }

    public function test_the_nested_creation_demands_the_percent_of_every_row(): void
    {
        $this->postJson('/api/v1/pension-cases', $this->payload([
            'income_concept_records' => [
                ['income_concept_id' => $this->incomeConceptId, 'amount' => '150.00'],
            ],
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('income_concept_records.0.applied_percent');
    }

    public function test_the_percent_range_is_zero_to_one_hundred(): void
    {
        // Above the ceiling.
        $this->postJson('/api/v1/pension-cases', $this->payload([
            'income_concept_records' => [
                ['income_concept_id' => $this->incomeConceptId, 'amount' => '150.00', 'applied_percent' => '100.01'],
            ],
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('income_concept_records.0.applied_percent');

        // Negative: the shape rule rejects the sign outright.
        $this->postJson('/api/v1/pension-cases', $this->payload([
            'income_concept_records' => [
                ['income_concept_id' => $this->incomeConceptId, 'amount' => '150.00', 'applied_percent' => '-1'],
            ],
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('income_concept_records.0.applied_percent');

        // A third decimal is not an exact two-decimal value.
        $this->postJson('/api/v1/pension-cases', $this->payload([
            'income_concept_records' => [
                ['income_concept_id' => $this->incomeConceptId, 'amount' => '150.00', 'applied_percent' => '25.505'],
            ],
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('income_concept_records.0.applied_percent');

        // The boundaries themselves are valid.
        $this->postJson('/api/v1/pension-cases', $this->payload([
            'income_concept_records' => [
                ['income_concept_id' => $this->incomeConceptId, 'amount' => '150.00', 'applied_percent' => '0'],
            ],
        ]))->assertStatus(201)
            ->assertJsonPath('data.income_concept_records.0.applied_percent', '0.00');
    }

    public function test_the_individual_endpoint_persists_the_applied_percent(): void
    {
        $case = $this->postJson('/api/v1/pension-cases', $this->payload())->json('data.id');

        $this->postJson("/api/v1/pension-cases/{$case}/income-concept-records", [
            'income_concept_id' => $this->incomeConceptId,
            'amount' => '150.00',
            'applied_percent' => '50.25',
        ])->assertStatus(201)
            ->assertJsonPath('data.applied_percent', '50.25');
    }

    public function test_the_individual_endpoint_demands_a_valid_percent(): void
    {
        $case = $this->postJson('/api/v1/pension-cases', $this->payload())->json('data.id');

        // Omitted.
        $this->postJson("/api/v1/pension-cases/{$case}/income-concept-records", [
            'income_concept_id' => $this->incomeConceptId,
            'amount' => '150.00',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('applied_percent');

        // Out of range.
        $this->postJson("/api/v1/pension-cases/{$case}/income-concept-records", [
            'income_concept_id' => $this->incomeConceptId,
            'amount' => '150.00',
            'applied_percent' => '150',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('applied_percent');

        // Third decimal.
        $this->postJson("/api/v1/pension-cases/{$case}/income-concept-records", [
            'income_concept_id' => $this->incomeConceptId,
            'amount' => '150.00',
            'applied_percent' => '50.255',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('applied_percent');
    }
}
