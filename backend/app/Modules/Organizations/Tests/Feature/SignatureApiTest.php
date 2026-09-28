<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Tests\Feature;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\EntityType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Municipality;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Organization;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Position;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Province;
use App\Modules\Organizations\Infrastructure\Persistence\Models\AuthorizedSignature;
use App\Modules\Organizations\Infrastructure\Persistence\Models\Entity;
use App\Modules\People\Infrastructure\Persistence\Models\Person;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Authorized signatures /api/v1/authorized-signatures (RF-ENT-003):
 * the entity+person+position tern is unique, the optional validity
 * window is validated (RN-006 style ordering), the status is derived
 * at read time and the revocation keeps the historical row (soft
 * delete) for the audit trail.
 */
final class SignatureApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Entity $entity;

    private Person $person;

    private Position $position;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsRole('admin');

        $province = Province::query()->create(['code' => '12', 'name' => 'Holguín']);
        $municipality = Municipality::query()->create([
            'province_id' => $province->id, 'code' => '01', 'name' => 'Holguín',
        ]);
        $organization = Organization::query()->create(['code' => 'MTSS', 'name' => 'Ministerio de Trabajo y Seguridad Social']);
        $type = EntityType::query()->create(['code' => 'EMP', 'name' => 'Empresa']);

        $this->entity = Entity::query()->create([
            'code' => 'ENT-0001', 'tax_id_number' => '11000000001',
            'organization_id' => $organization->id,
            'province_id' => $province->id, 'municipality_id' => $municipality->id,
            'entity_type_id' => $type->id,
            'address' => 'Calle 1', 'social_purpose' => 'Empresa de servicios',
        ]);
        $this->person = Person::factory()->create();
        $this->position = Position::query()->create(['name' => 'Director General', 'description' => 'Firma por la entidad']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'entity_id' => $this->entity->id,
            'person_id' => $this->person->id,
            'position_id' => $this->position->id,
        ], $overrides);
    }

    public function test_requires_authentication(): void
    {
        auth()->logout();

        $this->postJson('/api/v1/authorized-signatures', $this->payload())
            ->assertUnauthorized();
    }

    public function test_registers_a_signature_with_derived_active_status(): void
    {
        $this->postJson('/api/v1/authorized-signatures', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.entity.code', 'ENT-0001')
            ->assertJsonPath('data.person.identity_number', $this->person->identity_number)
            ->assertJsonPath('data.position.name', 'Director General')
            ->assertJsonPath('data.valid_from', null)
            ->assertJsonPath('data.valid_to', null)
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('authorized_signatures', [
            'entity_id' => $this->entity->id,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_registration_lands_in_the_audit_trail(): void
    {
        $this->postJson('/api/v1/authorized-signatures', $this->payload())->assertCreated();

        $this->assertDatabaseHas('activity_log', [
            'event' => 'created',
            'causer_id' => $this->user->id,
            'subject_type' => AuthorizedSignature::class,
        ]);
    }

    #[DataProvider('missingMandatoryProvider')]
    public function test_rejects_missing_mandatory_fields(string $field): void
    {
        $payload = $this->payload();
        unset($payload[$field]);

        $this->postJson('/api/v1/authorized-signatures', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    /** @return array<string, array{0: string}> */
    public static function missingMandatoryProvider(): array
    {
        return [
            'entity_id' => ['entity_id'],
            'person_id' => ['person_id'],
            'position_id' => ['position_id'],
        ];
    }

    public function test_rejects_a_duplicated_tern(): void
    {
        $this->postJson('/api/v1/authorized-signatures', $this->payload())->assertCreated();

        $this->postJson('/api/v1/authorized-signatures', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entity_id');
    }

    public function test_allows_the_same_person_in_other_entity_or_position(): void
    {
        $this->postJson('/api/v1/authorized-signatures', $this->payload())->assertCreated();

        $otherPosition = Position::query()->create(['name' => 'Subdirector Económico']);
        $this->postJson('/api/v1/authorized-signatures', $this->payload([
            'position_id' => $otherPosition->id,
        ]))->assertCreated();
    }

    public function test_rejects_a_validity_window_ending_before_it_starts(): void
    {
        $this->postJson('/api/v1/authorized-signatures', $this->payload([
            'valid_from' => '2026-01-01',
            'valid_to' => '2025-12-31',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('valid_to');
    }

    public function test_derives_the_status_from_the_validity_window(): void
    {
        $future = $this->postJson('/api/v1/authorized-signatures', $this->payload([
            'valid_from' => '2999-01-01', 'valid_to' => '2999-12-31',
        ]))->assertCreated()->json('data.id');

        $this->getJson("/api/v1/authorized-signatures/{$future}")
            ->assertOk()
            ->assertJsonPath('data.status', 'future');

        $expired = $this->postJson('/api/v1/authorized-signatures', $this->payload([
            'valid_from' => '2000-01-01', 'valid_to' => '2001-12-31',
            'person_id' => Person::factory()->create()->id,
        ]))->assertCreated()->json('data.id');

        $this->getJson("/api/v1/authorized-signatures/{$expired}")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');
    }

    public function test_updates_the_validity_window_with_audit(): void
    {
        $id = $this->postJson('/api/v1/authorized-signatures', $this->payload())->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/authorized-signatures/{$id}", [
            'valid_from' => '2020-01-01', 'valid_to' => '2030-12-31',
        ])->assertOk()
            ->assertJsonPath('data.valid_from', '2020-01-01')
            ->assertJsonPath('data.valid_to', '2030-12-31')
            ->assertJsonPath('data.status', 'active');

        $entry = Activity::query()
            ->where('subject_type', AuthorizedSignature::class)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        // The previous window was open (null start): the key must
        // exist and be null — null coalescing would falsify it.
        $old = $entry->properties['old'] ?? null;
        $this->assertIsArray($old);
        $this->assertArrayHasKey('valid_from', $old);
        $this->assertNull($old['valid_from']);
    }

    public function test_update_revalidates_the_window_ordering(): void
    {
        $id = $this->postJson('/api/v1/authorized-signatures', $this->payload([
            'valid_from' => '2020-01-01',
        ]))->assertCreated()->json('data.id');

        // valid_to before the stored valid_from is incoherent.
        $this->patchJson("/api/v1/authorized-signatures/{$id}", [
            'valid_to' => '2019-01-01',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('valid_to');
    }

    public function test_revocation_keeps_the_tern_reserved(): void
    {
        $id = $this->postJson('/api/v1/authorized-signatures', $this->payload())->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/authorized-signatures/{$id}")->assertOk();
        $this->getJson("/api/v1/authorized-signatures/{$id}")->assertNotFound();

        // The historical row keeps the tern reserved (RF-ENT-003).
        $this->postJson('/api/v1/authorized-signatures', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entity_id');

        $this->assertDatabaseHas('activity_log', [
            'event' => 'deleted',
            'subject_type' => AuthorizedSignature::class,
            'causer_id' => $this->user->id,
        ]);
    }

    public function test_lists_signatures_filtered_by_entity_and_status(): void
    {
        $this->postJson('/api/v1/authorized-signatures', $this->payload())->assertCreated();
        $this->postJson('/api/v1/authorized-signatures', $this->payload([
            'person_id' => Person::factory()->create()->id,
            'valid_from' => '2999-01-01', 'valid_to' => '2999-12-31',
        ]))->assertCreated();

        $this->getJson("/api/v1/authorized-signatures?entity_id={$this->entity->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson("/api/v1/authorized-signatures?entity_id={$this->entity->id}&status=future")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'future');

        $this->getJson('/api/v1/authorized-signatures?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'active');
    }
}
