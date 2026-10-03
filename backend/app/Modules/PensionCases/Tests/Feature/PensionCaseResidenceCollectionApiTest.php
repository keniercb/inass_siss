<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Tests\Feature;

use App\Modules\Catalogs\Domain\PaymentForm;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Agency;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\AgencyType;
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
use App\Modules\PensionCases\Tests\Concerns\ResetsCaseSequence;
use App\Modules\People\Infrastructure\Persistence\Models\Person;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Database\Factories\PersonFactory;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Promovente residence + collection group (Task 42, user correction,
 * SGP-36, RF-EXP-001): the case carries the promovente's current
 * address, residence geography (province + municipality, RN-04
 * coherent) and collection point (agency type + agency, the agency of
 * the declared type) — every field REQUIRED at the wire EXCEPT the
 * bank account, which the payment form of the collection agency type
 * decides: demanded with 'tarjeta magnetica' (422 on bank_account
 * when missing), optional with 'nomina electronica' (the omission
 * persists NULL, providing it is allowed).
 *
 * The whole group is EDITABLE through the PUT (explicit user
 * decision: it can be modified) with the store's mirror probes and
 * the conditional bank-account demand re-evaluated against the
 * RESULTING state of the PATCH semantics.
 */
final class PensionCaseResidenceCollectionApiTest extends TestCase
{
    use RefreshDatabase, ResetsCaseSequence;

    private Person $applicant;

    private Entity $entity;

    private Office $office;

    private User $operator;

    private Province $province;

    private Municipality $municipality;

    private AgencyType $magneticType;

    private AgencyType $payrollType;

    private Agency $magneticAgency;

    private Agency $payrollAgency;

    private int $positionId;

    private int $occupationalCategoryId;

    private int $educationalLevelId;

    private int $scientificCategoryId;

    private int $pensionTypeId;

    private int $pensionRegimeId;

    protected function setUp(): void
    {
        parent::setUp();

        // Declares the declared-scope sequences (ADR-17) and keeps the
        // case consecutive free of pre-declarations (ADR-34).
        $this->seed(SettingsSeeder::class);

        $this->province = Province::query()->create(['code' => '11', 'name' => 'La Habana']);
        $this->municipality = Municipality::query()->create(['province_id' => $this->province->id, 'code' => '03', 'name' => 'Playa']);
        $otherProvince = Province::query()->create(['code' => '12', 'name' => 'Holguín']);
        Municipality::query()->create(['province_id' => $otherProvince->id, 'code' => '07', 'name' => 'Holguín']);

        $officeType = OfficeType::query()->create(['code' => 'MUN', 'name' => 'Municipal']);
        $organization = Organization::query()->create(['code' => 'MTSS', 'name' => 'Ministerio de Trabajo']);
        $entityType = EntityType::query()->create(['code' => 'EMP', 'name' => 'Empresa']);

        $this->applicant = Person::factory()->create([
            'identity_number' => PersonFactory::identity('M', '1962-03-10'),
            'birth_date' => '1962-03-10',
            'sex' => 'M',
        ]);

        $this->office = Office::query()->create([
            'office_type_id' => $officeType->id,
            'province_id' => $this->province->id,
            'municipality_id' => $this->municipality->id,
            'address' => 'Calle 42 #7, Playa',
        ]);

        $this->entity = Entity::query()->create([
            'code' => 'ENT-42',
            'name' => 'Servicios Técnicos',
            'tax_id_number' => '11000012345',
            'organization_id' => $organization->id,
            'province_id' => $this->province->id,
            'municipality_id' => $this->municipality->id,
            'entity_type_id' => $entityType->id,
            'address' => 'Calle 100 #0',
            'social_purpose' => 'Servicios técnicos',
        ]);

        // Task 42: the two payment forms of the collection — the
        // magnetic card demands the bank account, the electronic
        // payroll leaves it optional.
        $this->magneticType = AgencyType::query()->create([
            'code' => 'TM',
            'name' => 'Agencia de tarjeta',
            'payment_form' => PaymentForm::TarjetaMagnetica->value,
        ]);
        $this->payrollType = AgencyType::query()->create([
            'code' => 'NE',
            'name' => 'Agencia de nómina',
            'payment_form' => PaymentForm::NominaElectronica->value,
        ]);

        $this->magneticAgency = Agency::query()->create([
            'code' => 'BPA-TM-1',
            'name' => 'Agencia BPA tarjeta',
            'province_id' => $this->province->id,
            'municipality_id' => $this->municipality->id,
            'agency_type_id' => $this->magneticType->id,
        ]);
        $this->payrollAgency = Agency::query()->create([
            'code' => 'BPA-NE-1',
            'name' => 'Agencia BPA nómina',
            'province_id' => $this->province->id,
            'municipality_id' => $this->municipality->id,
            'agency_type_id' => $this->payrollType->id,
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

        $this->operator = $this->actingAsRole('operator');
        $this->operator->forceFill(['office_id' => $this->office->id])->save();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
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
            // Task 42: promovente residence + collection group.
            'current_address' => 'Calle 8 #10 entre 5 y 7, Playa',
            'residence_province_id' => $this->province->id,
            'residence_municipality_id' => $this->municipality->id,
            'collection_agency_type_id' => $this->magneticType->id,
            'collection_agency_id' => $this->magneticAgency->id,
            'bank_account' => '01234567890123456789012345678',
        ], $overrides);
    }

    public function test_creates_a_case_with_the_promovente_residence_and_collection_group(): void
    {
        $response = $this->postJson('/api/v1/pension-cases', $this->payload());

        $response->assertStatus(201)
            ->assertJsonPath('data.current_address', 'Calle 8 #10 entre 5 y 7, Playa')
            ->assertJsonPath('data.residence_province_id', $this->province->id)
            ->assertJsonPath('data.residence_municipality_id', $this->municipality->id)
            ->assertJsonPath('data.collection_agency_type_id', $this->magneticType->id)
            ->assertJsonPath('data.collection_agency_id', $this->magneticAgency->id)
            ->assertJsonPath('data.bank_account', '01234567890123456789012345678');

        // Projections: province, municipality, agency type with its
        // payment form and the full agency.
        $this->assertSame($this->province->code, $response->json('data.residence_province.code'));
        $this->assertSame($this->municipality->code, $response->json('data.residence_municipality.code'));
        $this->assertSame(PaymentForm::TarjetaMagnetica->value, $response->json('data.collection_agency_type.payment_form'));
        $this->assertSame($this->magneticAgency->code, $response->json('data.collection_agency.code'));
        $this->assertSame($this->magneticAgency->name, $response->json('data.collection_agency.name'));
    }

    public function test_the_group_is_required_at_the_wire(): void
    {
        $payload = $this->payload();
        unset(
            $payload['current_address'],
            $payload['residence_province_id'],
            $payload['residence_municipality_id'],
            $payload['collection_agency_type_id'],
            $payload['collection_agency_id'],
        );

        $this->postJson('/api/v1/pension-cases', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_address');
    }

    public function test_the_bank_account_is_required_when_the_collection_type_pays_by_magnetic_card(): void
    {
        $payload = $this->payload();
        unset($payload['bank_account']);

        // Missing: the magnetic card form demands the account.
        $this->postJson('/api/v1/pension-cases', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('bank_account');

        // Empty string normalizes to NULL — same demand.
        $this->postJson('/api/v1/pension-cases', $this->payload(['bank_account' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('bank_account');

        // Provided: the case is created with the account.
        $created = $this->postJson('/api/v1/pension-cases', $this->payload(['applicant_person_id' => Person::factory()->create()->id]));
        $created->assertStatus(201)
            ->assertJsonPath('data.bank_account', '01234567890123456789012345678');
    }

    public function test_the_bank_account_is_optional_when_the_collection_type_pays_by_electronic_payroll(): void
    {
        // Omitted with nomina electronica: persists NULL, 201.
        $payload = $this->payload();
        unset($payload['bank_account']);
        $payload['collection_agency_type_id'] = $this->payrollType->id;
        $payload['collection_agency_id'] = $this->payrollAgency->id;

        $this->postJson('/api/v1/pension-cases', $payload)
            ->assertStatus(201)
            ->assertJsonPath('data.bank_account', null);

        // Provided anyway: allowed (optional, not forbidden).
        $this->postJson('/api/v1/pension-cases', $this->payload([
            'applicant_person_id' => Person::factory()->create()->id,
            'collection_agency_type_id' => $this->payrollType->id,
            'collection_agency_id' => $this->payrollAgency->id,
        ]))->assertStatus(201)
            ->assertJsonPath('data.bank_account', '01234567890123456789012345678');
    }

    public function test_the_residence_municipality_must_belong_to_the_residence_province(): void
    {
        $foreign = Municipality::query()->where('code', '07')->firstOrFail();

        $this->postJson('/api/v1/pension-cases', $this->payload([
            'residence_municipality_id' => $foreign->id,
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('residence_municipality_id');
    }

    public function test_the_collection_agency_must_belong_to_the_declared_type(): void
    {
        $this->postJson('/api/v1/pension-cases', $this->payload([
            'collection_agency_type_id' => $this->payrollType->id,
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('collection_agency_id');
    }

    public function test_deactivated_references_of_the_group_answer_422(): void
    {
        $deactivatedAgency = Agency::query()->create([
            'code' => 'BPA-DEL-1',
            'name' => 'Agencia desactivada',
            'province_id' => $this->province->id,
            'municipality_id' => $this->municipality->id,
            'agency_type_id' => $this->magneticType->id,
        ]);
        $deactivatedAgency->delete();

        $this->postJson('/api/v1/pension-cases', $this->payload([
            'collection_agency_id' => $deactivatedAgency->id,
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('collection_agency_id');

        $deactivatedType = AgencyType::query()->create([
            'code' => 'DEL',
            'name' => 'Tipo desactivado',
            'payment_form' => PaymentForm::NominaElectronica->value,
        ]);
        $deactivatedType->delete();

        $this->postJson('/api/v1/pension-cases', $this->payload([
            'collection_agency_type_id' => $deactivatedType->id,
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('collection_agency_type_id');
    }

    public function test_the_group_is_editable_through_the_put(): void
    {
        $case = $this->postJson('/api/v1/pension-cases', $this->payload())->json('data.id');

        // Move the collection to the electronic payroll agency and
        // clear the account: allowed (the resulting payment form
        // leaves the account optional).
        $this->putJson("/api/v1/pension-cases/{$case}", [
            'current_address' => 'Calle 23 #100, Vedado',
            'collection_agency_type_id' => $this->payrollType->id,
            'collection_agency_id' => $this->payrollAgency->id,
            'bank_account' => null,
        ])->assertStatus(200)
            ->assertJsonPath('data.current_address', 'Calle 23 #100, Vedado')
            ->assertJsonPath('data.collection_agency_id', $this->payrollAgency->id)
            ->assertJsonPath('data.bank_account', null)
            ->assertJsonPath('data.residence_municipality_id', $this->municipality->id);

        // Switch back to the magnetic card type WITHOUT an account:
        // the conditional demand is re-evaluated against the
        // RESULTING state — 422 on bank_account.
        $this->putJson("/api/v1/pension-cases/{$case}", [
            'collection_agency_type_id' => $this->magneticType->id,
            'collection_agency_id' => $this->magneticAgency->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('bank_account');
    }

    public function test_the_put_probes_mirror_the_store_for_the_group(): void
    {
        $case = $this->postJson('/api/v1/pension-cases', $this->payload())->json('data.id');
        $foreign = Municipality::query()->where('code', '07')->firstOrFail();

        $this->putJson("/api/v1/pension-cases/{$case}", [
            'residence_municipality_id' => $foreign->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('residence_municipality_id');

        // The residence move never persisted.
        $this->getJson("/api/v1/pension-cases/{$case}")
            ->assertOk()
            ->assertJsonPath('data.residence_municipality_id', $this->municipality->id);
    }
}
