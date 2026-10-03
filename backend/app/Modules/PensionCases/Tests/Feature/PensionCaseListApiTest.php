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
use App\Modules\PensionCases\Domain\CaseStatus;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\PensionCase;
use App\Modules\People\Infrastructure\Persistence\Models\Person;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Case listing GET /api/v1/pension-cases (RF-EXP-011) under the Task
 * 41 user correction (SGP-35): the listing is TERRITORIALLY scoped —
 * only the cases whose office matches the OFFICE OF THE AUTHENTICATED
 * USER load, and the office NEVER travels in the request: an
 * office_id query filter answers 422 prohibited, the same wire
 * contract the store has held for the payload field since user rule
 * 0 (ADR-33). The server resolves the scope from the actor's
 * assignment through the Shared office port.
 *
 * The scope is FAIL-CLOSED: an actor without an office — a
 * legitimate account state (RF-SEG-001/ADR-29, the Sprint 6
 * territorial-filtering groundwork) — matches no office, so their
 * page is EMPTY, never the unscoped directory. The scope composes
 * with the remaining wire filters (status, persona, número, rango de
 * fechas): they narrow INSIDE the actor's office, never across it.
 */
final class PensionCaseListApiTest extends TestCase
{
    use RefreshDatabase;

    private Office $northOffice;

    private Office $eastOffice;

    private Person $northApplicant;

    private Person $eastApplicant;

    private User $reader;

    /** @var array<string, int|string> */
    private array $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        // Two territorial offices (ADR-29/ADR-31): the reader will
        // belong to the north one, and the east one stays invisible
        // to them no matter how many cases it holds.
        $north = Province::query()->create(['code' => '11', 'name' => 'La Habana']);
        $northMunicipality = Municipality::query()->create([
            'province_id' => $north->id, 'code' => '03', 'name' => 'Playa',
        ]);
        $east = Province::query()->create(['code' => '12', 'name' => 'Holguín']);
        $eastMunicipality = Municipality::query()->create([
            'province_id' => $east->id, 'code' => '01', 'name' => 'Holguín',
        ]);
        $municipal = OfficeType::query()->create(['code' => 'MUN', 'name' => 'Municipal']);

        $this->northOffice = Office::query()->create([
            'office_type_id' => $municipal->id,
            'province_id' => $north->id,
            'municipality_id' => $northMunicipality->id,
            'address' => 'Calle 42 #7, Playa',
        ]);
        $this->eastOffice = Office::query()->create([
            'office_type_id' => $municipal->id,
            'province_id' => $east->id,
            'municipality_id' => $eastMunicipality->id,
            'address' => 'Calle Martí #100, Holguín',
        ]);

        // Shared catalogs and entity: both cases classify the same
        // way, so the ONLY difference the listing can react to is the
        // territorial office.
        $organization = Organization::query()->create(['code' => 'MTSS', 'name' => 'Ministerio de Trabajo']);
        $entityType = EntityType::query()->create(['code' => 'EMP', 'name' => 'Empresa']);
        $entity = Entity::query()->create([
            'code' => 'ENT-01',
            'name' => 'Servicios Técnicos',
            'tax_id_number' => '11000012345',
            'organization_id' => $organization->id,
            'province_id' => $north->id,
            'municipality_id' => $northMunicipality->id,
            'entity_type_id' => $entityType->id,
            'address' => 'Calle 100 #0',
            'social_purpose' => 'Servicios técnicos',
        ]);
        // Task 42: the collection point of the promovente (the
        // electronic payroll form keeps the bank account optional).
        $collectionAgencyType = AgencyType::query()->create([
            'code' => 'NE',
            'name' => 'Agencia de nómina',
            'payment_form' => PaymentForm::NominaElectronica->value,
        ]);
        $collectionAgency = Agency::query()->create([
            'code' => 'BPA-NE-1',
            'name' => 'Agencia BPA nómina',
            'province_id' => $north->id,
            'municipality_id' => $northMunicipality->id,
            'agency_type_id' => $collectionAgencyType->id,
        ]);

        $this->catalog = [
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
            // Task 42: the promovente residence + collection group
            // shared by both territorial fixtures.
            'current_address' => 'Calle 8 #10, Playa',
            'residence_province_id' => $north->id,
            'residence_municipality_id' => $northMunicipality->id,
            'collection_agency_type_id' => $collectionAgencyType->id,
            'collection_agency_id' => $collectionAgency->id,
        ];

        // One case captured per office (direct fixtures: the number
        // sequence belongs to the store surface).
        $this->northApplicant = Person::factory()->create([
            'identity_number' => PersonFactory::identity('M', '1962-03-10'),
            'birth_date' => '1962-03-10',
            'sex' => 'M',
        ]);
        $this->eastApplicant = Person::factory()->create([
            'identity_number' => PersonFactory::identity('F', '1957-03-13'),
            'birth_date' => '1957-03-13',
            'sex' => 'F',
        ]);
        $this->caseIn($this->northOffice, $this->northApplicant, '11032690001');
        $this->caseIn($this->eastOffice, $this->eastApplicant, '12012690002');

        // The reader BELONGS to the north office (ADR-29: one office
        // at most) — the listing must answer with that scope alone.
        $this->reader = $this->actingAsRole('specialist');
        $this->reader->forceFill(['office_id' => $this->northOffice->id])->save();
    }

    /**
     * Direct case fixture inside the given office (numbers outside
     * the sequence domain, the RBAC fixture pattern).
     */
    private function caseIn(Office $office, Person $applicant, string $number, CaseStatus $status = CaseStatus::Submitted): PensionCase
    {
        /** @var PensionCase $case */
        $case = PensionCase::query()->create(array_merge($this->catalog, [
            'number' => $number,
            'requested_at' => now()->toDateString(),
            'status' => $status->value,
            'applicant_person_id' => $applicant->id,
            'office_id' => $office->id,
            'rebel_army_member' => false,
            'internationalist' => false,
            'last_salary' => '5000.00',
        ]));

        return $case;
    }

    public function test_the_listing_only_loads_the_cases_of_the_authenticated_users_office(): void
    {
        // The SGP-35 rule itself: the north reader sees ONE case —
        // the north one — even though the east office holds another.
        $this->getJson('/api/v1/pension-cases')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.office_id', $this->northOffice->id)
            ->assertJsonPath('data.0.number', '11032690001')
            ->assertJsonPath('data.0.applicant.id', $this->northApplicant->id)
            ->assertJsonPath('meta.total', 1);

        // Reassigning the actor moves the scope (ADR-29): the same
        // surface now answers with the east case alone — the office
        // of the cases, not of the request.
        $this->reader->forceFill(['office_id' => $this->eastOffice->id])->save();

        $this->getJson('/api/v1/pension-cases')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.office_id', $this->eastOffice->id)
            ->assertJsonPath('data.0.number', '12012690002')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_the_listing_rejects_an_office_id_in_the_query(): void
    {
        // The wire contract of the scope: a client that still sends
        // the office filter gets a 422 instead of silently believing
        // it drove the listing — the sibling of the store's rule 0
        // rejection of the payload field.
        $this->getJson('/api/v1/pension-cases?office_id='.$this->eastOffice->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['office_id']);
    }

    public function test_an_actor_without_an_office_gets_an_empty_page(): void
    {
        // Fail-closed: an officeless account is a legitimate state
        // (RF-SEG-001/ADR-29) and it matches no territorial scope —
        // the answer is an EMPTY page with a sound pagination
        // envelope, never the unscoped directory.
        $this->reader->forceFill(['office_id' => null])->save();

        $this->getJson('/api/v1/pension-cases')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.current_page', 1);
    }

    public function test_the_office_scope_composes_with_the_wire_filters(): void
    {
        // A second north case OUTSIDE the submitted status: the wire
        // filters narrow INSIDE the actor's scope, never across it.
        $this->caseIn($this->northOffice, Person::factory()->create(), '11032690003', CaseStatus::Approved);

        $this->getJson('/api/v1/pension-cases?status=submitted')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', '11032690001');

        // The east applicant is invisible to the north reader even
        // when the query asks for the person explicitly.
        $this->getJson('/api/v1/pension-cases?applicant_person_id='.$this->eastApplicant->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // And the north applicant stays visible with the same
        // filter — the scope narrows, it does not swallow.
        $this->getJson('/api/v1/pension-cases?applicant_person_id='.$this->northApplicant->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', '11032690001');
    }
}
