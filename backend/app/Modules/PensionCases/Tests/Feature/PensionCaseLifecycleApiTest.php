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
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Database\Factories\PersonFactory;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Lifecycle API of the case aggregate itself (user correction,
 * SGP-34): the DELETE is a SOFT delete gated by the editable state —
 * only a `submitted` case can be eliminated, the row survives with
 * its deleted_at and the soft delete RELEASES the one-open-case
 * reservation so the operator can re-capture — and the PUT edits the
 * case fields while the PROMOVENTE of the pension stays immutable:
 * every person-sphere field (applicant, filer, rebel army pair,
 * internationalist flag, contact pair, termination date) answers 422
 * instead of silently drifting the promovente the registry already
 * knows. Both writes answer cases.edit and only run while the case
 * sits in submitted, exactly like the subrecord highs/removals.
 */
final class PensionCaseLifecycleApiTest extends TestCase
{
    use RefreshDatabase, ResetsCaseSequence;

    private Person $applicant;

    private Entity $entity;

    private PensionCase $case;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        // Declares the ANNUAL `pension_case:{year}` sequence scopes
        // (ADR-17/ADR-32).
        $this->seed(SettingsSeeder::class);

        $this->operator = $this->actingAsRole('operator');

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
            'internationalist' => true,
            'phone' => '+53 5 555 1234',
            'popular_council' => 'Consejo Popular Playa',
            'termination_date' => '2025-07-31',
            'filed_by_person_id' => Person::factory()->create()->id,
            'last_salary' => '5000.00',
        ]);

        return [$applicant, $entity, $case];
    }

    /**
     * Re-capture payload for the SAME applicant after a delete: the
     * promovente keeps every id the seedCase fixture created.
     *
     * @return array<string, mixed>
     */
    private function storePayload(): array
    {
        $case = $this->case;

        return [
            'applicant_person_id' => $this->applicant->id,
            'employer_entity_id' => $case->employer_entity_id,
            'position_id' => $case->position_id,
            'occupational_category_id' => $case->occupational_category_id,
            'educational_level_id' => $case->educational_level_id,
            'scientific_category_id' => $case->scientific_category_id,
            'pension_type_id' => $case->pension_type_id,
            'pension_regime_id' => $case->pension_regime_id,
            'rebel_army_member' => false,
            'internationalist' => false,
            'last_salary' => '5000.00',
        ];
    }

    // ------------------------------------------------------------------
    // DELETE — soft delete gated by the editable state.
    // ------------------------------------------------------------------

    public function test_soft_deletes_a_submitted_case(): void
    {
        $this->deleteJson("/api/v1/pension-cases/{$this->case->id}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Case deleted.');

        // SOFT delete: the row survives with its deleted_at — the
        // evidence and the audit trail stay answerable (RN-001).
        $this->assertSoftDeleted('pension_cases', ['id' => $this->case->id]);

        // The public surface no longer sees it: detail 404…
        $this->getJson("/api/v1/pension-cases/{$this->case->id}")->assertStatus(404);

        // …and the listing no longer counts it.
        $this->getJson('/api/v1/pension-cases')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_soft_delete_keeps_the_subrecords_in_the_database(): void
    {
        $this->postJson("/api/v1/pension-cases/{$this->case->id}/salary-records", [
            'year' => 2023, 'earned_salary' => '4800.00',
        ])->assertStatus(201);

        $this->deleteJson("/api/v1/pension-cases/{$this->case->id}")->assertStatus(200);

        // The history stays physically: the subrecords were never the
        // delete target, the case row was.
        $this->assertDatabaseCount('salary_records', 1);
    }

    public function test_the_soft_delete_lands_in_the_audit_trail(): void
    {
        $this->deleteJson("/api/v1/pension-cases/{$this->case->id}")->assertStatus(200);

        $deleted = Activity::query()
            ->where('event', 'deleted')
            ->where('subject_type', PensionCase::class)
            ->first();

        $this->assertNotNull($deleted);
        // The previous values ride the entry (ADR-19): the bitácora
        // keeps what the case looked like before the elimination.
        $this->assertSame($this->case->number, $deleted->properties['old']['number'] ?? null);
    }

    public function test_the_soft_delete_releases_the_open_case_reservation(): void
    {
        // Rule 0: the registering user needs an office for the case
        // to assume (ADR-33).
        $this->operator->forceFill(['office_id' => $this->case->office_id])->save();

        $this->deleteJson("/api/v1/pension-cases/{$this->case->id}")->assertStatus(200);

        // SGP-34: the soft delete must RELEASE the one-open-case
        // reservation — the operator deleted a mistaken capture, so
        // re-capturing the same applicant has to work again. The
        // physical backstop (open_case_key) turns NULL on deleted_at.
        $this->postJson('/api/v1/pension-cases', $this->storePayload())
            ->assertStatus(201)
            ->assertJsonPath('data.applicant_person_id', $this->applicant->id);
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
    public function test_rejects_the_delete_outside_submitted(string $status): void
    {
        $this->case->status = CaseStatus::from($status);
        $this->case->save();

        $this->deleteJson("/api/v1/pension-cases/{$this->case->id}")
            ->assertStatus(409)
            ->assertJsonPath('status', $status);

        // Nothing was eliminated.
        $this->assertNotSoftDeleted('pension_cases', ['id' => $this->case->id]);
    }

    public function test_deleting_an_unknown_case_answers_404(): void
    {
        $this->deleteJson('/api/v1/pension-cases/999')->assertStatus(404);
    }

    public function test_deleting_an_already_deleted_case_answers_404(): void
    {
        $this->deleteJson("/api/v1/pension-cases/{$this->case->id}")->assertStatus(200);

        // The SoftDeletes global scope already hides it: a second
        // elimination is a 404, not a 409 nor a 200.
        $this->deleteJson("/api/v1/pension-cases/{$this->case->id}")->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // PUT — case edition with an immutable promovente.
    // ------------------------------------------------------------------

    public function test_updates_the_editable_fields_of_a_submitted_case(): void
    {
        $otherEntity = Entity::query()->create([
            'code' => 'ENT-02',
            'name' => 'Comercializadora',
            'tax_id_number' => '11000099999',
            'organization_id' => $this->entity->organization_id,
            'province_id' => $this->entity->province_id,
            'municipality_id' => $this->entity->municipality_id,
            'entity_type_id' => $this->entity->entity_type_id,
            'address' => 'Calle 200 #1',
            'social_purpose' => 'Comercio',
        ]);

        $this->putJson("/api/v1/pension-cases/{$this->case->id}", [
            'employer_entity_id' => $otherEntity->id,
            'last_salary' => '6200.00',
            'requested_at' => now()->toDateString(),
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.employer_entity_id', $otherEntity->id)
            ->assertJsonPath('data.last_salary', '6200.00')
            ->assertJsonStructure(['warnings']);

        $this->assertDatabaseHas('pension_cases', [
            'id' => $this->case->id,
            'employer_entity_id' => $otherEntity->id,
            'last_salary' => '6200.00',
        ]);
    }

    public function test_the_update_only_touches_the_declared_fields(): void
    {
        $this->putJson("/api/v1/pension-cases/{$this->case->id}", [
            'last_salary' => '7100.00',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.last_salary', '7100.00');

        // Everything else keeps its value — including the promovente
        // fields the case carries.
        $this->assertDatabaseHas('pension_cases', [
            'id' => $this->case->id,
            'employer_entity_id' => $this->entity->id,
            'phone' => '+53 5 555 1234',
            'popular_council' => 'Consejo Popular Playa',
            'termination_date' => '2025-07-31',
            'internationalist' => 1,
        ]);
    }

    public function test_an_empty_update_answers_the_untouched_case(): void
    {
        $this->putJson("/api/v1/pension-cases/{$this->case->id}", [])
            ->assertStatus(200)
            ->assertJsonPath('data.number', $this->case->number)
            ->assertJsonPath('data.last_salary', '5000.00');
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function promoventeFields(): array
    {
        return [
            'applicant' => ['applicant_person_id', 999],
            'filer' => ['filed_by_person_id', 999],
            'rebel army member' => ['rebel_army_member', true],
            'rebel army join date' => ['rebel_army_join_date', '1958-01-01'],
            'internationalist' => ['internationalist', false],
            'phone' => ['phone', '+53 5 000 0000'],
            'popular council' => ['popular_council', 'Otro consejo'],
            'termination date' => ['termination_date', '2026-01-31'],
        ];
    }

    #[DataProvider('promoventeFields')]
    public function test_rejects_every_promovente_field(string $field, mixed $value): void
    {
        $this->putJson("/api/v1/pension-cases/{$this->case->id}", [$field => $value])
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field]);

        // The promovente never drifts: the stored value survives the
        // rejected attempt untouched.
        $this->assertDatabaseHas('pension_cases', [
            'id' => $this->case->id,
            'phone' => '+53 5 555 1234',
            'popular_council' => 'Consejo Popular Playa',
            'termination_date' => '2025-07-31',
            'internationalist' => 1,
        ]);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function lifecycleFields(): array
    {
        return [
            'office' => ['office_id', 2],
            'number' => ['number', '11032600099'],
            'status' => ['status', 'approved'],
        ];
    }

    #[DataProvider('lifecycleFields')]
    public function test_rejects_the_lifecycle_fields(string $field, mixed $value): void
    {
        $this->putJson("/api/v1/pension-cases/{$this->case->id}", [$field => $value])
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field]);
    }

    #[DataProvider('lockedStates')]
    public function test_rejects_the_update_outside_submitted(string $status): void
    {
        $this->case->status = CaseStatus::from($status);
        $this->case->save();

        $this->putJson("/api/v1/pension-cases/{$this->case->id}", [
            'last_salary' => '6200.00',
        ])
            ->assertStatus(409)
            ->assertJsonPath('status', $status);

        $this->assertDatabaseHas('pension_cases', [
            'id' => $this->case->id,
            'last_salary' => '5000.00',
        ]);
    }

    public function test_rejects_an_unknown_or_deactivated_entity(): void
    {
        $this->putJson("/api/v1/pension-cases/{$this->case->id}", [
            'employer_entity_id' => 999999,
        ])->assertStatus(422)->assertJsonValidationErrors(['employer_entity_id']);
    }

    public function test_rejects_an_unknown_catalog_reference(): void
    {
        $this->putJson("/api/v1/pension-cases/{$this->case->id}", [
            'pension_type_id' => 999999,
        ])->assertStatus(422)->assertJsonValidationErrors(['pension_type_id']);
    }

    public function test_rejects_a_future_requested_at(): void
    {
        $this->putJson("/api/v1/pension-cases/{$this->case->id}", [
            'requested_at' => now()->addDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['requested_at']);
    }

    public function test_rejects_an_invalid_last_salary(): void
    {
        $this->putJson("/api/v1/pension-cases/{$this->case->id}", [
            'last_salary' => 'not-a-number',
        ])->assertStatus(422)->assertJsonValidationErrors(['last_salary']);
    }

    public function test_updating_an_unknown_case_answers_404(): void
    {
        $this->putJson('/api/v1/pension-cases/999', [
            'last_salary' => '6200.00',
        ])->assertStatus(404);
    }

    public function test_the_update_lands_in_the_audit_trail(): void
    {
        $this->putJson("/api/v1/pension-cases/{$this->case->id}", [
            'last_salary' => '6200.00',
        ])->assertStatus(200);

        $updated = Activity::query()
            ->where('event', 'updated')
            ->where('subject_type', PensionCase::class)
            ->first();

        $this->assertNotNull($updated);
        // The bitácora keeps both sides of the change (ADR-19).
        $this->assertSame('5000.00', (string) ($updated->properties['old']['last_salary'] ?? ''));
        $this->assertSame('6200.00', (string) ($updated->properties['attributes']['last_salary'] ?? ''));
    }
}
