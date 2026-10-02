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
use App\Modules\PensionCases\Domain\CaseStatus;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\IncomeConceptRecord;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\PensionCase;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\SalaryRecord;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\ServiceRecord;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\WorkCycle;
use App\Modules\PensionCases\Tests\Concerns\ResetsCaseSequence;
use App\Modules\People\Infrastructure\Persistence\Models\Person;
use Database\Factories\PersonFactory;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Subrecord API inside the case (RF-EXP-002..004, plan S5.3/S5.4,
 * user rule 5): highs and removals gated by the editable state,
 * semantic probes of the uniqueness and date rules, the FIFTEEN row
 * ceiling of the salary series (user rule 1) and the income concept
 * records — one declared value per (case, concept) pair. Since the
 * Task 37 user correction the service periods are CLOSED AND
 * DISJOINT: the end date is mandatory and strictly after the start
 * (422) and no period may overlap an existing one (422) — only the
 * missing salary years of RF-EXP-002 remain an advisory warning.
 */
final class PensionCaseSubrecordsApiTest extends TestCase
{
    use RefreshDatabase, ResetsCaseSequence;

    private Person $applicant;

    private Entity $entity;

    private PensionCase $case;

    private int $incomeConceptId;

    private int $otherIncomeConceptId;

    protected function setUp(): void
    {
        parent::setUp();

        // Declares the ANNUAL `pension_case:{year}` sequence scopes
        // (ADR-17/ADR-32).
        $this->seed(SettingsSeeder::class);

        $this->actingAsRole('operator');

        [$this->applicant, $this->entity, $this->case] = $this->seedCase();
    }

    /**
     * @return array{Person, Entity, PensionCase}
     */
    private function seedCase(): array
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

        $case = PensionCase::query()->create([
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

        return [$applicant, $entity, $case];
    }

    public function test_adds_a_salary_record_with_the_series_warning(): void
    {
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/salary-records", [
            'year' => 2018,
            'earned_salary' => '4600.00',
        ])->assertStatus(201)->assertJsonPath('data.year', 2018);

        // 2019-2020 stay missing between 2018 and the next declared
        // year: the RF-EXP-002 advertisement fires.
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/salary-records", [
            'year' => 2021,
            'earned_salary' => '4800.00',
        ])
            ->assertStatus(201)
            ->assertJsonPath('warnings.missing_salary_years', [2019, 2020]);
    }

    public function test_rejects_a_repeated_year(): void
    {
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/salary-records", [
            'year' => 2023, 'earned_salary' => '4800.00',
        ])->assertStatus(201);

        $this->postJson("/api/v1/pension-cases/{$this->case->id}/salary-records", [
            'year' => 2023, 'earned_salary' => '4900.00',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['year']);
    }

    public function test_rejects_years_outside_the_range(): void
    {
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/salary-records", [
            'year' => 1949, 'earned_salary' => '4800.00',
        ])->assertStatus(422)->assertJsonValidationErrors(['year']);

        // The ceiling is the current year + 1 (RF-EXP-002) — next
        // year's anticipated income is declarable, the year after is
        // not.
        $nextYear = now()->year + 1;
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/salary-records", [
            'year' => $nextYear, 'earned_salary' => '4800.00',
        ])->assertStatus(201);

        $this->postJson("/api/v1/pension-cases/{$this->case->id}/salary-records", [
            'year' => $nextYear + 1, 'earned_salary' => '4800.00',
        ])->assertStatus(422)->assertJsonValidationErrors(['year']);
    }

    public function test_removes_a_salary_record_and_refreshes_the_warning(): void
    {
        $first = $this->postJson("/api/v1/pension-cases/{$this->case->id}/salary-records", [
            'year' => 2018, 'earned_salary' => '4600.00',
        ])->json('data.id');

        $this->postJson("/api/v1/pension-cases/{$this->case->id}/salary-records", [
            'year' => 2021, 'earned_salary' => '4800.00',
        ])->assertStatus(201);

        $this->deleteJson("/api/v1/pension-cases/{$this->case->id}/salary-records/{$first}")
            ->assertStatus(200)
            // With 2018 gone, only 2021 stands: no interior, no gap.
            ->assertJsonPath('warnings.missing_salary_years', []);

        $this->assertSame(1, SalaryRecord::query()->count());
    }

    public function test_removing_an_unknown_salary_record_answers_404(): void
    {
        $this->deleteJson("/api/v1/pension-cases/{$this->case->id}/salary-records/999")
            ->assertStatus(404);
    }

    public function test_adds_disjoint_service_records_and_rejects_overlaps(): void
    {
        // Task 37: two periods may never share a day — the first
        // closed link stands and a disjoint later one joins it, but
        // a third crossing the first is REJECTED (422), not
        // advertised.
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/service-records", [
            'entity_id' => $this->entity->id,
            'start_date' => '2000-01-01',
            'end_date' => '2005-12-31',
        ])->assertStatus(201)->assertJsonPath('data.is_appendix', false)->assertJsonPath('data.declaration_form', 'Documental');

        // Disjoint: the day after the stored end starts clean.
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/service-records", [
            'entity_id' => $this->entity->id,
            'start_date' => '2006-01-01',
            'end_date' => '2010-12-31',
        ])->assertStatus(201);

        // Overlapping: shares 2010-12-31 with the second link.
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/service-records", [
            'entity_id' => $this->entity->id,
            'start_date' => '2010-12-31',
            'end_date' => '2015-12-31',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);

        $this->assertSame(2, ServiceRecord::query()->count());
    }

    public function test_rejects_a_service_overlapping_an_earlier_period(): void
    {
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/service-records", [
            'entity_id' => $this->entity->id,
            'start_date' => '2000-01-01',
            'end_date' => '2005-12-31',
        ])->assertStatus(201);

        // Partial cross inside the stored period.
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/service-records", [
            'entity_id' => $this->entity->id,
            'start_date' => '2004-06-01',
            'end_date' => '2008-12-31',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);

        // Containment inside the stored period overlaps too.
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/service-records", [
            'entity_id' => $this->entity->id,
            'start_date' => '2001-01-01',
            'end_date' => '2004-12-31',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);

        $this->assertSame(1, ServiceRecord::query()->count());
    }

    public function test_rejects_a_service_without_an_end_date(): void
    {
        // Task 37: the end date is MANDATORY — an omitted end_date is
        // a 422 on the field, never an open link.
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/service-records", [
            'entity_id' => $this->entity->id,
            'start_date' => '2010-01-01',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);

        $this->assertSame(0, ServiceRecord::query()->count());
    }

    public function test_rejects_a_service_ending_when_it_starts(): void
    {
        // Task 37: posterior means STRICTLY after — a one-day service
        // (end == start) is a 422.
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/service-records", [
            'entity_id' => $this->entity->id,
            'start_date' => '2010-01-01',
            'end_date' => '2010-01-01',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);

        $this->assertSame(0, ServiceRecord::query()->count());
    }

    public function test_rejects_a_service_ending_before_it_starts(): void
    {
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/service-records", [
            'entity_id' => $this->entity->id,
            'start_date' => '2010-01-01',
            'end_date' => '2009-12-31',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);
    }

    public function test_rejects_an_unknown_or_deactivated_entity(): void
    {
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/service-records", [
            'entity_id' => 999999,
            'start_date' => '2010-01-01',
            'end_date' => '2015-12-31',
        ])->assertStatus(422)->assertJsonValidationErrors(['entity_id']);
    }

    public function test_adds_a_service_record_with_the_default_documental_form(): void
    {
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/service-records", [
            'entity_id' => $this->entity->id,
            'start_date' => '2000-01-01',
            'end_date' => '2005-12-31',
        ])->assertStatus(201)->assertJsonPath('data.declaration_form', 'Documental');
    }

    public function test_adds_a_service_record_declared_by_testimony(): void
    {
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/service-records", [
            'entity_id' => $this->entity->id,
            'start_date' => '2000-01-01',
            'end_date' => '2005-12-31',
            'declaration_form' => 'Testifical',
        ])->assertStatus(201)->assertJsonPath('data.declaration_form', 'Testifical');
    }

    public function test_rejects_an_unknown_declaration_form(): void
    {
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/service-records", [
            'entity_id' => $this->entity->id,
            'start_date' => '2010-01-01',
            'end_date' => '2015-12-31',
            'declaration_form' => 'Oral',
        ])->assertStatus(422)->assertJsonValidationErrors(['declaration_form']);
    }

    public function test_the_detail_carries_the_declaration_form_of_every_service(): void
    {
        // End-to-end projection (Task 33): the case detail answers
        // the form of every row — a declared Testifical first, the
        // Documental default second.
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/service-records", [
            'entity_id' => $this->entity->id,
            'start_date' => '1980-01-01',
            'end_date' => '1990-12-31',
            'declaration_form' => 'Testifical',
        ])->assertStatus(201);

        $this->postJson("/api/v1/pension-cases/{$this->case->id}/service-records", [
            'entity_id' => $this->entity->id,
            'start_date' => '2000-01-01',
            'end_date' => '2010-12-31',
        ])->assertStatus(201);

        $this->getJson("/api/v1/pension-cases/{$this->case->id}")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.service_records')
            ->assertJsonPath('data.service_records.0.declaration_form', 'Testifical')
            ->assertJsonPath('data.service_records.1.declaration_form', 'Documental');
    }

    public function test_removes_a_service_record(): void
    {
        $id = $this->postJson("/api/v1/pension-cases/{$this->case->id}/service-records", [
            'entity_id' => $this->entity->id,
            'start_date' => '2010-01-01',
            'end_date' => '2015-12-31',
        ])->json('data.id');

        $this->deleteJson("/api/v1/pension-cases/{$this->case->id}/service-records/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Service record removed.');

        $this->assertSame(0, ServiceRecord::query()->count());
    }

    public function test_adds_and_removes_work_cycles(): void
    {
        $id = $this->postJson("/api/v1/pension-cases/{$this->case->id}/work-cycles", [
            'planned_days' => 300, 'actual_days' => 280, 'cycles_count' => 1,
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.actual_days', 280)
            ->json('data.id');

        $this->deleteJson("/api/v1/pension-cases/{$this->case->id}/work-cycles/{$id}")
            ->assertStatus(200);

        $this->assertSame(0, WorkCycle::query()->count());
    }

    public function test_rejects_negative_work_cycle_values(): void
    {
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/work-cycles", [
            'planned_days' => -1, 'actual_days' => 280, 'cycles_count' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors(['planned_days']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function lockedStates(): array
    {
        return [
            'under review' => ['under_review'],
            'approved' => ['approved'],
            'rejected' => ['rejected'],
        ];
    }

    #[DataProvider('lockedStates')]
    public function test_subrecords_are_frozen_outside_submitted(string $status): void
    {
        $this->case->status = CaseStatus::from($status);
        $this->case->save();

        // The add answers 409 with the current status…
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/salary-records", [
            'year' => 2023, 'earned_salary' => '4800.00',
        ])
            ->assertStatus(409)
            ->assertJsonPath('status', $status);

        // …and so does the removal.
        $this->deleteJson("/api/v1/pension-cases/{$this->case->id}/salary-records/1")
            ->assertStatus(409)
            ->assertJsonPath('status', $status);
    }

    public function test_an_unknown_case_answers_404(): void
    {
        $this->postJson('/api/v1/pension-cases/999/salary-records', [
            'year' => 2023, 'earned_salary' => '4800.00',
        ])->assertStatus(404);

        $this->getJson('/api/v1/pension-cases/999')->assertStatus(404);
    }

    public function test_the_detail_carries_subrecords_and_warnings(): void
    {
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/salary-records", [
            'year' => 2018, 'earned_salary' => '4600.00',
        ])->assertStatus(201);

        $this->postJson("/api/v1/pension-cases/{$this->case->id}/salary-records", [
            'year' => 2021, 'earned_salary' => '4800.00',
        ])->assertStatus(201);

        $this->postJson("/api/v1/pension-cases/{$this->case->id}/service-records", [
            'entity_id' => $this->entity->id,
            'start_date' => '2000-01-01',
            'end_date' => '2010-12-31',
        ])->assertStatus(201);

        $this->getJson("/api/v1/pension-cases/{$this->case->id}")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.salary_records')
            ->assertJsonCount(1, 'data.service_records')
            ->assertJsonPath('warnings.missing_salary_years', [2019, 2020])
            // Task 37: overlaps and open links are impossible now —
            // the envelope carries the salary analysis only.
            ->assertJsonMissingPath('warnings.overlapping_services')
            ->assertJsonMissingPath('warnings.open_services');
    }

    public function test_subrecord_writes_land_in_the_trail_with_their_values(): void
    {
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/salary-records", [
            'year' => 2023, 'earned_salary' => '4800.00',
        ])->assertStatus(201);

        $created = Activity::query()
            ->where('event', 'created')
            ->where('subject_type', SalaryRecord::class)
            ->first();

        $this->assertNotNull($created);
        $this->assertSame(2023, (int) ($created->properties['attributes']['year'] ?? 0));

        $this->deleteJson("/api/v1/pension-cases/{$this->case->id}/salary-records/{$created->subject_id}")
            ->assertStatus(200);

        $deleted = Activity::query()
            ->where('event', 'deleted')
            ->where('subject_type', SalaryRecord::class)
            ->first();

        // The removal keeps the previous values in the append-only
        // trail (ADR-19): the row itself is gone, the evidence stays.
        $this->assertNotNull($deleted);
        $this->assertSame(2023, (int) ($deleted->properties['old']['year'] ?? 0));
    }

    public function test_the_listing_filters_by_status_office_and_person(): void
    {
        $response = $this->getJson('/api/v1/pension-cases?status=submitted')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', '11-2026-90001');

        $this->getJson('/api/v1/pension-cases?status=approved')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/v1/pension-cases?applicant_person_id={$this->applicant->id}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/pension-cases?status=whatever')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_the_salary_series_is_capped_at_fifteen_rows(): void
    {
        // Rule 1: fifteen declared years stand; the sixteenth is a
        // 422 on the wire field, never a silent truncation.
        for ($year = 2010; $year <= 2024; $year++) {
            $this->postJson("/api/v1/pension-cases/{$this->case->id}/salary-records", [
                'year' => $year, 'earned_salary' => '4800.00',
            ])->assertStatus(201);
        }

        $liveRows = (int) SalaryRecord::query()->where('pension_case_id', $this->case->id)->count();
        $this->assertSame(15, $liveRows);

        $this->postJson("/api/v1/pension-cases/{$this->case->id}/salary-records", [
            'year' => 2025, 'earned_salary' => '4800.00',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['salary_records']);

        $liveRows = (int) SalaryRecord::query()->where('pension_case_id', $this->case->id)->count();
        $this->assertSame(15, $liveRows);
    }

    public function test_removing_a_row_frees_a_slot_of_the_salary_ceiling(): void
    {
        $firstId = null;

        for ($year = 2010; $year <= 2024; $year++) {
            $firstId ??= $this->postJson("/api/v1/pension-cases/{$this->case->id}/salary-records", [
                'year' => $year, 'earned_salary' => '4800.00',
            ])->json('data.id');
        }

        $this->deleteJson("/api/v1/pension-cases/{$this->case->id}/salary-records/{$firstId}")
            ->assertStatus(200);

        // The ceiling counts LIVE rows: with one removed, a new year
        // enters the series again.
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/salary-records", [
            'year' => 2025, 'earned_salary' => '4800.00',
        ])->assertStatus(201);
    }

    public function test_adds_an_income_concept_record(): void
    {
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/income-concept-records", [
            'income_concept_id' => $this->incomeConceptId,
            'amount' => '150.00',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.income_concept_id', $this->incomeConceptId)
            ->assertJsonPath('data.amount', '150.00')
            ->assertJsonPath('data.pension_case_id', $this->case->id);

        $this->assertSame(1, IncomeConceptRecord::query()->count());
    }

    public function test_rejects_a_declared_concept_twice(): void
    {
        // One value per (case, concept) pair: the semantic probe of
        // the UNIQUE answers 422 on income_concept_id.
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/income-concept-records", [
            'income_concept_id' => $this->incomeConceptId,
            'amount' => '150.00',
        ])->assertStatus(201);

        $this->postJson("/api/v1/pension-cases/{$this->case->id}/income-concept-records", [
            'income_concept_id' => $this->incomeConceptId,
            'amount' => '200.00',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['income_concept_id']);

        // A DIFFERENT concept still enters: the pair is the key, not
        // the case alone.
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/income-concept-records", [
            'income_concept_id' => $this->otherIncomeConceptId,
            'amount' => '80.50',
        ])->assertStatus(201);
    }

    public function test_rejects_an_unknown_or_deactivated_income_concept(): void
    {
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/income-concept-records", [
            'income_concept_id' => 999999,
            'amount' => '150.00',
        ])->assertStatus(422)->assertJsonValidationErrors(['income_concept_id']);

        $concept = IncomeConcept::query()->whereKey($this->otherIncomeConceptId)->firstOrFail();
        $concept->delete();

        $this->postJson("/api/v1/pension-cases/{$this->case->id}/income-concept-records", [
            'income_concept_id' => $this->otherIncomeConceptId,
            'amount' => '80.50',
        ])->assertStatus(422)->assertJsonValidationErrors(['income_concept_id']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAmounts(): array
    {
        return [
            'negative' => ['-1.00'],
            'three decimals' => ['150.123'],
            'not a number' => ['abc'],
            'thousands separator' => ['1,000.00'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_rejects_amounts_outside_the_money_shape(string $amount): void
    {
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/income-concept-records", [
            'income_concept_id' => $this->incomeConceptId,
            'amount' => $amount,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_removes_an_income_concept_record(): void
    {
        $id = $this->postJson("/api/v1/pension-cases/{$this->case->id}/income-concept-records", [
            'income_concept_id' => $this->incomeConceptId,
            'amount' => '150.00',
        ])->json('data.id');

        $this->deleteJson("/api/v1/pension-cases/{$this->case->id}/income-concept-records/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Income concept record removed.');

        $this->assertSame(0, IncomeConceptRecord::query()->count());
    }

    public function test_removing_an_unknown_income_concept_record_answers_404(): void
    {
        $this->deleteJson("/api/v1/pension-cases/{$this->case->id}/income-concept-records/999")
            ->assertStatus(404);
    }

    #[DataProvider('lockedStates')]
    public function test_income_concept_records_are_frozen_outside_submitted(string $status): void
    {
        $this->case->status = CaseStatus::from($status);
        $this->case->save();

        $this->postJson("/api/v1/pension-cases/{$this->case->id}/income-concept-records", [
            'income_concept_id' => $this->incomeConceptId,
            'amount' => '150.00',
        ])
            ->assertStatus(409)
            ->assertJsonPath('status', $status);

        $this->deleteJson("/api/v1/pension-cases/{$this->case->id}/income-concept-records/1")
            ->assertStatus(409)
            ->assertJsonPath('status', $status);
    }

    public function test_an_unknown_case_answers_404_for_income_concepts(): void
    {
        $this->postJson('/api/v1/pension-cases/999/income-concept-records', [
            'income_concept_id' => $this->incomeConceptId,
            'amount' => '150.00',
        ])->assertStatus(404);
    }

    public function test_requires_authentication(): void
    {
        auth()->logout();

        $this->postJson("/api/v1/pension-cases/{$this->case->id}/salary-records", [
            'year' => 2023, 'earned_salary' => '4800.00',
        ])->assertUnauthorized();

        $this->postJson("/api/v1/pension-cases/{$this->case->id}/income-concept-records", [
            'income_concept_id' => $this->incomeConceptId,
            'amount' => '150.00',
        ])->assertUnauthorized();
    }
}
