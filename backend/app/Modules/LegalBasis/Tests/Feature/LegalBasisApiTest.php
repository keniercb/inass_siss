<?php

declare(strict_types=1);

namespace App\Modules\LegalBasis\Tests\Feature;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\LegalBasisType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Organization;
use App\Modules\LegalBasis\Infrastructure\Persistence\Models\LegalBasis;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Legal corpus registration and consultation /api/v1/legal-bases
 * (RF-LEG-002..004, RN-006, H-11): the type-number-year tern is
 * unique with the year derived from the issue date, the date
 * ordering is validated in the service and backed by database
 * CHECKs, the status is derived at read time and the derogation is
 * an editable, auditable date — never a destructive action.
 */
final class LegalBasisApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private LegalBasisType $type;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        // Admin holds legalbases.manage; role-specific denial lives in
        // RbacLegalBasisApiTest.
        $this->user = $this->actingAsRole('admin');

        $this->type = LegalBasisType::query()->create(['code' => 'LEY', 'name' => 'Ley']);
        $this->organization = Organization::query()->create(['code' => 'MTSS', 'name' => 'Ministerio de Trabajo y Seguridad Social']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'legal_basis_type_id' => $this->type->id,
            'number' => '128',
            'issue_date' => '2019-07-16',
            'effective_date' => '2019-08-01',
            'issuing_organization_id' => $this->organization->id,
            'reference' => 'Gaceta Oficial Ordinaria No. 45 de 2019',
        ], $overrides);
    }

    public function test_requires_authentication(): void
    {
        auth()->logout();

        $this->postJson('/api/v1/legal-bases', $this->payload())
            ->assertUnauthorized();
    }

    public function test_registers_a_legal_basis_with_derived_year(): void
    {
        $this->postJson('/api/v1/legal-bases', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.number', '128')
            ->assertJsonPath('data.year', 2019)
            ->assertJsonPath('data.type.name', 'Ley')
            ->assertJsonPath('data.organization.name', 'Ministerio de Trabajo y Seguridad Social')
            ->assertJsonPath('data.issue_date', '2019-07-16')
            ->assertJsonPath('data.effective_date', '2019-08-01')
            ->assertJsonPath('data.derogation_date', null)
            ->assertJsonPath('data.status', 'effective');

        // H-11: the year column is derived from issue_date, never sent.
        $this->assertDatabaseHas('legal_bases', [
            'number' => '128',
            'year' => 2019,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_registration_lands_in_the_audit_trail(): void
    {
        $this->postJson('/api/v1/legal-bases', $this->payload())->assertCreated();

        $this->assertDatabaseHas('activity_log', [
            'event' => 'created',
            'causer_id' => $this->user->id,
            'subject_type' => LegalBasis::class,
        ]);
    }

    #[DataProvider('missingMandatoryProvider')]
    public function test_rejects_missing_mandatory_fields(string $field): void
    {
        $payload = $this->payload();
        unset($payload[$field]);

        $this->postJson('/api/v1/legal-bases', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    /** @return array<string, array{0: string}> */
    public static function missingMandatoryProvider(): array
    {
        return [
            'legal_basis_type_id' => ['legal_basis_type_id'],
            'number' => ['number'],
            'issue_date' => ['issue_date'],
            'effective_date' => ['effective_date'],
            'issuing_organization_id' => ['issuing_organization_id'],
        ];
    }

    public function test_rejects_a_puesta_en_vigor_before_the_emision_rn006(): void
    {
        $this->postJson('/api/v1/legal-bases', $this->payload([
            'effective_date' => '2019-07-15',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('effective_date');
    }

    public function test_rejects_a_derogation_before_the_puesta_en_vigor_rn006(): void
    {
        $this->postJson('/api/v1/legal-bases', $this->payload([
            'derogation_date' => '2019-07-31',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('derogation_date');
    }

    public function test_rejects_a_duplicated_tern(): void
    {
        $this->postJson('/api/v1/legal-bases', $this->payload())->assertCreated();

        // Same type + number + year (year derives from issue_date).
        $this->postJson('/api/v1/legal-bases', $this->payload([
            'issue_date' => '2019-12-01',
            'effective_date' => '2020-01-01',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('number');
    }

    public function test_allows_the_same_number_in_other_type_or_year(): void
    {
        $this->postJson('/api/v1/legal-bases', $this->payload())->assertCreated();

        $otherType = LegalBasisType::query()->create(['code' => 'DEC', 'name' => 'Decreto']);
        $this->postJson('/api/v1/legal-bases', $this->payload([
            'legal_basis_type_id' => $otherType->id,
        ]))->assertCreated();

        $this->postJson('/api/v1/legal-bases', $this->payload([
            'issue_date' => '2020-07-16',
            'effective_date' => '2020-08-01',
        ]))->assertCreated();
    }

    public function test_type_number_and_issue_date_are_immutable(): void
    {
        $id = $this->postJson('/api/v1/legal-bases', $this->payload())->assertCreated()->json('data.id');

        $otherType = LegalBasisType::query()->create(['code' => 'DEC', 'name' => 'Decreto']);

        $this->patchJson("/api/v1/legal-bases/{$id}", ['legal_basis_type_id' => $otherType->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('legal_basis_type_id');

        $this->patchJson("/api/v1/legal-bases/{$id}", ['number' => '999'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('number');

        $this->patchJson("/api/v1/legal-bases/{$id}", ['issue_date' => '2019-07-17'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('issue_date');
    }

    public function test_updates_reference_and_dates_with_audit(): void
    {
        $id = $this->postJson('/api/v1/legal-bases', $this->payload())->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/legal-bases/{$id}", [
            'reference' => 'Gaceta Oficial Extraordinaria No. 7 de 2019',
        ])->assertOk()
            ->assertJsonPath('data.reference', 'Gaceta Oficial Extraordinaria No. 7 de 2019');

        $entry = Activity::query()
            ->where('subject_type', LegalBasis::class)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $old = $entry->properties['old'] ?? null;
        $this->assertIsArray($old);
        $this->assertSame('Gaceta Oficial Ordinaria No. 45 de 2019', $old['reference'] ?? null);
    }

    public function test_update_revalidates_date_ordering_against_the_resulting_state(): void
    {
        $id = $this->postJson('/api/v1/legal-bases', $this->payload())->assertCreated()->json('data.id');

        // Moving the puesta en vigor before the stored issue date is incoherent.
        $this->patchJson("/api/v1/legal-bases/{$id}", [
            'effective_date' => '2019-07-01',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('effective_date');
    }

    public function test_derogation_flips_the_derived_status(): void
    {
        $id = $this->postJson('/api/v1/legal-bases', $this->payload())->assertCreated()->json('data.id');

        $this->getJson("/api/v1/legal-bases/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'effective');

        $this->patchJson("/api/v1/legal-bases/{$id}", [
            'derogation_date' => '2020-01-01',
        ])->assertOk()
            ->assertJsonPath('data.derogation_date', '2020-01-01');

        $this->getJson("/api/v1/legal-bases/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'derogated');
    }

    public function test_future_bases_derive_their_status(): void
    {
        $id = $this->postJson('/api/v1/legal-bases', $this->payload([
            'issue_date' => '2026-09-01',
            'effective_date' => '2999-01-01',
        ]))->assertCreated()->json('data.id');

        $this->getJson("/api/v1/legal-bases/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'future');
    }

    public function test_deactivates_a_legal_basis_and_reserves_the_tern(): void
    {
        $id = $this->postJson('/api/v1/legal-bases', $this->payload())->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/legal-bases/{$id}")->assertOk();
        $this->getJson("/api/v1/legal-bases/{$id}")->assertNotFound();

        // The tern stays reserved by the deactivated row.
        $this->postJson('/api/v1/legal-bases', $this->payload())->assertUnprocessable()
            ->assertJsonValidationErrors('number');

        $this->assertDatabaseHas('activity_log', [
            'event' => 'deleted',
            'subject_type' => LegalBasis::class,
            'causer_id' => $this->user->id,
        ]);
    }

    public function test_lists_with_year_type_organization_and_text_filters(): void
    {
        $this->postJson('/api/v1/legal-bases', $this->payload())->assertCreated();
        $otherType = LegalBasisType::query()->create(['code' => 'RES', 'name' => 'Resolución']);
        $this->postJson('/api/v1/legal-bases', $this->payload([
            'legal_basis_type_id' => $otherType->id,
            'number' => '45',
            'issue_date' => '2020-03-10',
            'effective_date' => '2020-04-01',
            'reference' => 'Resolución del MTSS sobre seguridad social',
        ]))->assertCreated();

        // By year (RF-LEG-004).
        $this->getJson('/api/v1/legal-bases?year=2020')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', '45');

        // By type.
        $this->getJson("/api/v1/legal-bases?legal_basis_type_id={$otherType->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // By issuing organization (both share it: 2 rows).
        $this->getJson("/api/v1/legal-bases?organization_id={$this->organization->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // By reference text fragment.
        $this->getJson('/api/v1/legal-bases?q=seguridad')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // By number fragment.
        $this->getJson('/api/v1/legal-bases?q=128')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_lists_filter_by_derived_status(): void
    {
        // In force today (issue 2019, effective 2019, no derogation).
        $this->postJson('/api/v1/legal-bases', $this->payload())->assertCreated();

        // Derogated (effective 2019, derogated 2020).
        $this->postJson('/api/v1/legal-bases', $this->payload([
            'number' => '200',
            'derogation_date' => '2020-01-01',
        ]))->assertCreated();

        // Future (effective in 2999).
        $this->postJson('/api/v1/legal-bases', $this->payload([
            'number' => '300',
            'issue_date' => '2026-09-01',
            'effective_date' => '2999-01-01',
        ]))->assertCreated();

        // RF-LEG-003: the selector of vigentes for expediente approval.
        $this->getJson('/api/v1/legal-bases?status=effective')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'effective');

        $this->getJson('/api/v1/legal-bases?status=derogated')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/legal-bases?status=future')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_lists_are_paginated_with_the_envelope(): void
    {
        $this->getJson('/api/v1/legal-bases?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.current_page', 1);
    }
}
