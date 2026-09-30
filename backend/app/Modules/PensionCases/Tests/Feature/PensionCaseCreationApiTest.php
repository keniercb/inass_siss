<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Tests\Feature;

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
use App\Modules\PensionCases\Infrastructure\Persistence\Models\IncomeConceptRecord;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\PensionCase;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\SalaryRecord;
use App\Modules\PensionCases\Tests\Concerns\ResetsCaseSequence;
use App\Modules\People\Infrastructure\Persistence\Models\Person;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Database\Factories\PersonFactory;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Case creation POST /api/v1/pension-cases (RF-EXP-001, plan S5.2/S5.5,
 * user rules 0-5/ADR-32/ADR-33):
 *
 *  0. the case takes the OFFICE OF THE REGISTERING USER — office_id
 *     is not accepted in the payload anymore;
 *  1. at most FIFTEEN salary records per case;
 *  2. the number is composed of the registering office's province
 *     code (2), the current year (4) and the ANNUAL consecutive (5,
 *     zero padded), each section separated by a hyphen;
 *  3. the listing carries the FULL applicant projection;
 *  4. pension type/regime plus the rebel army membership pair
 *     (join date required when the membership is true, rejected
 *     when it is false);
 *  5. income concept records travel as nested subrecords of the
 *     atomic creation.
 *
 * The one-open-case rule, eligibility of the applicant and the
 * all-or-nothing subrecord creation stay as Sprint 5 left them.
 */
final class PensionCaseCreationApiTest extends TestCase
{
    use RefreshDatabase, ResetsCaseSequence;

    private Person $applicant;

    private Office $office;

    private Entity $entity;

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

        // Declares the ANNUAL `pension_case:{year}` sequence scopes
        // (ADR-17/ADR-32: scopes are seeded ahead; the generator
        // refuses undeclared plain scopes).
        $this->seed(SettingsSeeder::class);

        $province = Province::query()->create(['code' => '11', 'name' => 'La Habana']);
        $municipality = Municipality::query()->create(['province_id' => $province->id, 'code' => '03', 'name' => 'Playa']);
        $officeType = OfficeType::query()->create(['code' => 'MUN', 'name' => 'Municipal']);
        $organization = Organization::query()->create(['code' => 'MTSS', 'name' => 'Ministerio de Trabajo']);
        $entityType = EntityType::query()->create(['code' => 'EMP', 'name' => 'Empresa']);

        $this->applicant = Person::factory()->create([
            'identity_number' => PersonFactory::identity('M', '1962-03-10'),
            'birth_date' => '1962-03-10',
            'sex' => 'M',
            'address' => 'Calle 8 #10, Playa',
        ]);

        $this->office = Office::query()->create([
            'office_type_id' => $officeType->id,
            'province_id' => $province->id,
            'municipality_id' => $municipality->id,
            'address' => 'Calle 42 #7, Playa',
        ]);

        $this->entity = Entity::query()->create([
            'code' => 'ENT-01',
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
            'description' => 'Régimen general de seguridad social',
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

        // Operator: data capture registers cases in estado Solicitud
        // (section 2.2) — cases.create + cases.edit — BELONGING to the
        // registering office (ADR-33: the case assumes it).
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
            'last_salary' => '5000.00',
        ], $overrides);
    }

    /**
     * @param  list<int>  $years
     * @return list<array{year: int, earned_salary: string}>
     */
    private function salaryRows(array $years): array
    {
        return array_map(
            static fn (int $year): array => ['year' => $year, 'earned_salary' => '4800.00'],
            $years,
        );
    }

    public function test_creates_a_case_with_sequential_number_and_submitted_state(): void
    {
        $response = $this->postJson('/api/v1/pension-cases', $this->payload());

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.applicant_person_id', $this->applicant->id)
            ->assertJsonPath('data.last_salary', '5000.00')
            ->assertJsonPath('data.pension_type_id', $this->pensionTypeId)
            ->assertJsonPath('data.pension_regime_id', $this->pensionRegimeId)
            ->assertJsonPath('data.rebel_army_member', false)
            ->assertJsonPath('data.rebel_army_join_date', null);

        // Default requested_at: today (resolved through the clock).
        $this->assertSame(now()->toDateString(), $response->json('data.requested_at'));

        // The applicant projection travels for disambiguation.
        $this->assertSame($this->applicant->identity_number, $response->json('data.applicant.identity_number'));
    }

    public function test_the_case_takes_the_office_of_the_registering_user(): void
    {
        // Rule 0: the payload carries NO office_id — the case assumes
        // the office of the authenticated operator.
        $response = $this->postJson('/api/v1/pension-cases', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.office_id', $this->office->id);

        $case = PensionCase::query()->whereKey($response->json('data.id'))->first();
        $this->assertNotNull($case);
        $this->assertSame($this->office->id, $case->office_id);
    }

    public function test_office_id_in_the_payload_is_rejected(): void
    {
        // Rule 0's wire contract: sending office_id is a 422 — the
        // caller must never believe their value was honored.
        $this->postJson('/api/v1/pension-cases', $this->payload(['office_id' => $this->office->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['office_id']);
    }

    public function test_a_user_without_an_office_cannot_register_cases(): void
    {
        $this->operator->forceFill(['office_id' => null])->save();

        $this->postJson('/api/v1/pension-cases', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['office_id']);
    }

    public function test_a_user_whose_office_was_deactivated_cannot_register(): void
    {
        $this->office->delete();

        $this->postJson('/api/v1/pension-cases', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['office_id']);
    }

    public function test_the_number_is_composed_of_province_year_and_annual_consecutive(): void
    {
        // Rule 2: PP-YYYY-CCCCC — province code of the registering
        // office, current year, annual consecutive padded to five.
        $number = (string) $this->postJson('/api/v1/pension-cases', $this->payload())
            ->assertStatus(201)
            ->json('data.number');

        $this->assertMatchesRegularExpression('/^\d{2}-\d{4}-\d{5}$/', $number);
        $this->assertSame('11', substr($number, 0, 2));
        $this->assertSame(now()->format('Y'), substr($number, 3, 4));
        $this->assertSame('00001', substr($number, 8));
        $this->assertSame(11, strlen(str_replace('-', '', $number)));
    }

    public function test_the_annual_consecutive_advances_per_creation(): void
    {
        $first = (string) $this->postJson('/api/v1/pension-cases', $this->payload())
            ->assertStatus(201)
            ->json('data.number');

        $secondPerson = Person::factory()->create();
        $second = (string) $this->postJson(
            '/api/v1/pension-cases',
            $this->payload(['applicant_person_id' => $secondPerson->id]),
        )
            ->assertStatus(201)
            ->json('data.number');

        $this->assertSame((int) substr($first, 8) + 1, (int) substr($second, 8));
        // Same province and year: only the consecutive moved.
        $this->assertSame(substr($first, 0, 8), substr($second, 0, 8));
    }

    public function test_the_registering_office_province_must_carry_a_two_digit_code(): void
    {
        // The number derives its first section from the registering
        // office's province: a malformed catalog code answers 422 on
        // office_id instead of a broken number.
        $odd = Province::query()->create(['code' => '9', 'name' => 'Provincia de prueba']);
        $officeType = OfficeType::query()->where('code', 'MUN')->firstOrFail();
        $municipality = Municipality::query()->create(['province_id' => $odd->id, 'code' => '01', 'name' => 'Impar']);
        $office = Office::query()->create([
            'office_type_id' => $officeType->id,
            'province_id' => $odd->id,
            'municipality_id' => $municipality->id,
            'address' => 'Calle 1 #1',
        ]);

        $this->operator->forceFill(['office_id' => $office->id])->save();

        $this->postJson('/api/v1/pension-cases', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['office_id']);
    }

    public function test_fifteen_salary_records_are_accepted(): void
    {
        // Rule 1: the salary series of a case holds at most fifteen
        // rows — fifteen is the healthy shape.
        $this->postJson('/api/v1/pension-cases', $this->payload([
            'salary_records' => $this->salaryRows(range(2010, 2024)),
        ]))
            ->assertStatus(201)
            ->assertJsonCount(15, 'data.salary_records');
    }

    public function test_sixteen_salary_records_are_rejected(): void
    {
        $this->postJson('/api/v1/pension-cases', $this->payload([
            'salary_records' => $this->salaryRows(range(2009, 2024)),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['salary_records']);

        $this->assertSame(0, SalaryRecord::query()->count());
    }

    public function test_pension_type_and_regime_are_required(): void
    {
        $this->postJson('/api/v1/pension-cases', $this->payload(['pension_type_id' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pension_type_id']);

        $this->postJson('/api/v1/pension-cases', $this->payload(['pension_regime_id' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pension_regime_id']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidClassifiers(): array
    {
        return [
            'unknown pension type' => ['pension_type_id'],
            'unknown pension regime' => ['pension_regime_id'],
        ];
    }

    #[DataProvider('invalidClassifiers')]
    public function test_rejects_unknown_pension_classifiers(string $field): void
    {
        $this->postJson('/api/v1/pension-cases', $this->payload([$field => 999999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field]);
    }

    public function test_rebel_army_membership_demands_the_join_date(): void
    {
        $this->postJson('/api/v1/pension-cases', $this->payload(['rebel_army_member' => true]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rebel_army_join_date']);
    }

    public function test_rebel_army_membership_is_persisted_with_the_join_date(): void
    {
        $this->postJson('/api/v1/pension-cases', $this->payload([
            'rebel_army_member' => true,
            'rebel_army_join_date' => '1957-03-13',
        ]))
            ->assertStatus(201)
            ->assertJsonPath('data.rebel_army_member', true)
            ->assertJsonPath('data.rebel_army_join_date', '1957-03-13');
    }

    public function test_a_join_date_without_membership_is_rejected(): void
    {
        $this->postJson('/api/v1/pension-cases', $this->payload([
            'rebel_army_member' => false,
            'rebel_army_join_date' => '1957-03-13',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rebel_army_join_date']);
    }

    public function test_the_declared_income_concept_records_are_created(): void
    {
        // Rule 5: income concepts travel as nested subrecords of the
        // atomic creation — everything or nothing.
        $response = $this->postJson('/api/v1/pension-cases', $this->payload([
            'income_concept_records' => [
                ['income_concept_id' => $this->incomeConceptId, 'amount' => '150.00'],
                ['income_concept_id' => $this->otherIncomeConceptId, 'amount' => '80.50'],
            ],
        ]))
            ->assertStatus(201)
            ->assertJsonCount(2, 'data.income_concept_records');

        $rows = IncomeConceptRecord::query()
            ->where('pension_case_id', $response->json('data.id'))
            ->orderBy('income_concept_id')
            ->get();
        $first = $rows->first();
        $last = $rows->last();

        $this->assertNotNull($first);
        $this->assertNotNull($last);
        $this->assertSame(2, $rows->count());
        $this->assertSame($this->incomeConceptId, $first->income_concept_id);
        $this->assertSame('80.50', (string) $last->amount);
    }

    public function test_a_duplicated_income_concept_in_the_payload_is_rejected(): void
    {
        $this->postJson('/api/v1/pension-cases', $this->payload([
            'income_concept_records' => [
                ['income_concept_id' => $this->incomeConceptId, 'amount' => '150.00'],
                ['income_concept_id' => $this->incomeConceptId, 'amount' => '200.00'],
            ],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['income_concept_records.1.income_concept_id']);

        $this->assertSame(0, IncomeConceptRecord::query()->count());
    }

    public function test_an_unknown_income_concept_is_rejected(): void
    {
        $this->postJson('/api/v1/pension-cases', $this->payload([
            'income_concept_records' => [
                ['income_concept_id' => 999999, 'amount' => '150.00'],
            ],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['income_concept_records.0.income_concept_id']);
    }

    public function test_creates_the_declared_subrecords_atomically(): void
    {
        $response = $this->postJson('/api/v1/pension-cases', $this->payload([
            'salary_records' => [
                ['year' => 2022, 'earned_salary' => '4600.00'],
                ['year' => 2023, 'earned_salary' => '4800.00'],
            ],
            'service_records' => [
                ['entity_id' => $this->entity->id, 'start_date' => '2000-01-01', 'end_date' => '2015-12-31', 'is_appendix' => false],
                ['entity_id' => $this->entity->id, 'start_date' => '2016-01-01', 'end_date' => null, 'is_appendix' => true],
            ],
            'work_cycles' => [
                ['planned_days' => 300, 'actual_days' => 280, 'cycles_count' => 1],
            ],
            'income_concept_records' => [
                ['income_concept_id' => $this->incomeConceptId, 'amount' => '150.00'],
            ],
        ]))
            ->assertStatus(201)
            ->assertJsonCount(2, 'data.salary_records')
            ->assertJsonCount(2, 'data.service_records')
            ->assertJsonCount(1, 'data.work_cycles')
            ->assertJsonCount(1, 'data.income_concept_records')
            // RF-EXP-002: the interior gap 2022→2023 has no missing
            // year, so no warning fires.
            ->assertJsonPath('warnings.missing_salary_years', []);

        $caseId = $response->json('data.id') ?? $this->fail('The response must carry the case id.');
        $case = PensionCase::query()->whereKey($caseId)->first();
        $this->assertNotNull($case);
        $this->assertSame(2, $case->salaryRecords()->count());
        $this->assertSame(2, $case->serviceRecords()->count());
        $this->assertSame(1, $case->workCycles()->count());
        $this->assertSame(1, $case->incomeConceptRecords()->count());
    }

    public function test_an_invalid_subrecord_leaves_nothing_behind(): void
    {
        // S5.5: everything or nothing — a repeated year inside the
        // payload aborts before any row exists, and because the
        // semantic validation runs BEFORE the number is emitted, the
        // rejected attempt consumes nothing at all.
        $before = (string) $this->postJson('/api/v1/pension-cases', $this->payload())
            ->assertStatus(201)
            ->json('data.number');

        // A fresh applicant for the doomed attempt: the one-open-case
        // rule would otherwise answer 409 before the subrecord
        // validation gets a chance to speak.
        $doomed = Person::factory()->create();

        $this->postJson('/api/v1/pension-cases', $this->payload([
            'applicant_person_id' => $doomed->id,
            'salary_records' => [
                ['year' => 2022, 'earned_salary' => '4600.00'],
                ['year' => 2022, 'earned_salary' => '4800.00'],
            ],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['salary_records.1.year']);

        $this->assertSame(1, PensionCase::query()->count());
        $this->assertSame(0, SalaryRecord::query()->count());

        $this->postJson('/api/v1/pension-cases', $this->payload(['applicant_person_id' => $doomed->id]))
            ->assertStatus(201)
            // Every semantic validation completes BEFORE the number
            // is emitted, so a rejected payload burns nothing: the
            // next creation simply takes the next value. Only an
            // insert failure inside the transaction can burn a
            // number (RN-009 hole, accepted by design).
            ->assertJsonPath('data.number', $this->nextConsecutive($before));
    }

    public function test_the_listing_carries_the_full_applicant_projection(): void
    {
        // Rule 3: GET /pension-cases answers with EVERY field of the
        // promovente — not the disambiguation summary.
        $this->postJson('/api/v1/pension-cases', $this->payload())->assertStatus(201);

        $this->getJson('/api/v1/pension-cases')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.applicant.id', $this->applicant->id)
            ->assertJsonPath('data.0.applicant.identity_number', $this->applicant->identity_number)
            ->assertJsonPath('data.0.applicant.first_name', $this->applicant->first_name)
            ->assertJsonPath('data.0.applicant.first_surname', $this->applicant->first_surname)
            ->assertJsonPath('data.0.applicant.sex', 'M')
            ->assertJsonPath('data.0.applicant.address', 'Calle 8 #10, Playa')
            ->assertJsonPath('data.0.applicant.birth_date', '1962-03-10')
            ->assertJsonPath('data.0.applicant.deceased', false);
    }

    public function test_a_deceased_applicant_is_rejected(): void
    {
        $deceased = Person::factory()->create([
            'identity_number' => PersonFactory::identity('M', '1940-05-01'),
            'birth_date' => '1940-05-01',
            'death_date' => '2024-03-10',
        ]);

        $this->postJson('/api/v1/pension-cases', $this->payload(['applicant_person_id' => $deceased->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['applicant_person_id']);
    }

    public function test_a_deactivated_applicant_is_rejected(): void
    {
        $deactivated = Person::factory()->create();
        $deactivated->delete();

        $this->postJson('/api/v1/pension-cases', $this->payload(['applicant_person_id' => $deactivated->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['applicant_person_id']);
    }

    public function test_an_unknown_applicant_is_rejected(): void
    {
        $this->postJson('/api/v1/pension-cases', $this->payload(['applicant_person_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['applicant_person_id']);
    }

    public function test_a_person_with_an_open_case_answers_409_with_it(): void
    {
        $number = $this->postJson('/api/v1/pension-cases', $this->payload())
            ->assertStatus(201)
            ->json('data.number');

        $this->postJson('/api/v1/pension-cases', $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('case.number', $number)
            ->assertJsonPath('case.status', 'submitted');
    }

    public function test_a_resolved_case_frees_the_person(): void
    {
        $caseId = $this->postJson('/api/v1/pension-cases', $this->payload())
            ->assertStatus(201)
            ->json('data.id');

        // The S6 transitions are not here yet: the terminal state is
        // written directly to exercise the physical generated-column
        // rule (open_case_key becomes NULL).
        PensionCase::query()->whereKey($caseId)->update(['status' => 'rejected']);

        $this->postJson('/api/v1/pension-cases', $this->payload())
            ->assertStatus(201);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidReferences(): array
    {
        return [
            'unknown entity' => ['employer_entity_id', '999999'],
            'unknown position' => ['position_id', '999999'],
            'unknown occupational category' => ['occupational_category_id', '999999'],
            'unknown educational level' => ['educational_level_id', '999999'],
            'unknown scientific category' => ['scientific_category_id', '999999'],
        ];
    }

    #[DataProvider('invalidReferences')]
    public function test_rejects_dead_references(string $field, string $value): void
    {
        $this->postJson('/api/v1/pension-cases', $this->payload([$field => (int) $value]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidSalaries(): array
    {
        return [
            'negative' => ['-1.00'],
            'three decimals' => ['5000.123'],
            'not a number' => ['abc'],
            'thousands separator' => ['5,000.00'],
        ];
    }

    #[DataProvider('invalidSalaries')]
    public function test_rejects_salaries_outside_the_money_shape(string $salary): void
    {
        $this->postJson('/api/v1/pension-cases', $this->payload(['last_salary' => $salary]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['last_salary']);
    }

    public function test_a_future_request_date_is_rejected(): void
    {
        $this->postJson('/api/v1/pension-cases', $this->payload(['requested_at' => now()->addDay()->toDateString()]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['requested_at']);
    }

    public function test_a_past_request_date_is_accepted(): void
    {
        $this->postJson('/api/v1/pension-cases', $this->payload(['requested_at' => '2026-01-15']))
            ->assertStatus(201)
            ->assertJsonPath('data.requested_at', '2026-01-15');
    }

    public function test_requires_authentication(): void
    {
        auth()->logout();

        $this->postJson('/api/v1/pension-cases', $this->payload())
            ->assertUnauthorized();
    }

    public function test_the_creation_lands_in_the_append_only_trail(): void
    {
        $caseId = $this->postJson('/api/v1/pension-cases', $this->payload())
            ->assertStatus(201)
            ->json('data.id');

        $entry = Activity::query()
            ->where('event', 'created')
            ->where('subject_type', PensionCase::class)
            ->first();

        $this->assertNotNull($entry, 'The case creation must reach the bitácora (ADR-19).');
        $this->assertSame((string) $caseId, (string) $entry->subject_id);
        $this->assertNotNull($entry->properties);
        $this->assertArrayHasKey('attributes', $entry->properties->toArray());
    }

    /**
     * PP-YYYY-CCCCC arithmetic helper: the next consecutive of the
     * same province and year.
     */
    private function nextConsecutive(string $number): string
    {
        $consecutive = (int) substr($number, 8) + 1;

        return substr($number, 0, 8).str_pad((string) $consecutive, 5, '0', STR_PAD_LEFT);
    }
}
