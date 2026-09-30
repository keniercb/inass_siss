<?php

declare(strict_types=1);

namespace App\Modules\Security\Tests\Feature;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\Race;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Append-only activity trail (RF-AUD-001, RNF-005, ADR-19) plus its
 * read surface (RF-AUD-003) and the audited restore (RF-AUD-004).
 *
 * Every critical write — catalog entries here, the demo setting in
 * other suites — must land in the spatie activity_log table with the
 * acting user, the previous and new values, and the request_id of the
 * HTTP interaction. The query surface is read-only by construction:
 * no route can ever mutate the trail.
 */
final class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->actingAs($this->admin);
    }

    public function test_create_write_lands_in_the_trail_with_causer_and_request_id(): void
    {
        $response = $this->postJson('/api/v1/catalogs/races', ['code' => 'MUL', 'name' => 'Mestiza o Mulata']);

        $response->assertCreated();

        $this->assertDatabaseHas('activity_log', [
            'event' => 'created',
            'causer_id' => $this->admin->id,
            'subject_type' => Race::class,
        ]);

        $entry = $this->latestActivity();

        $this->assertNotNull($this->property($entry, 'request_id'));
        $this->assertSame('Mestiza o Mulata', $this->attribute($entry, 'attributes', 'name'));
        $this->assertNull($this->property($entry, 'old'));
    }

    public function test_forwarded_request_id_is_honored_for_correlation(): void
    {
        $this->withHeader('X-Request-Id', 'integrador-abc-123')
            ->postJson('/api/v1/catalogs/races', ['code' => 'CHN', 'name' => 'China']);

        $entry = $this->latestActivity();

        $this->assertSame('integrador-abc-123', $this->property($entry, 'request_id'));
    }

    public function test_update_write_records_previous_and_new_values(): void
    {
        $id = Race::query()->create(['name' => 'Otra'])->id;

        $this->patchJson("/api/v1/catalogs/races/{$id}", ['name' => 'Otra raza'])
            ->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'event' => 'updated',
            'subject_type' => Race::class,
            'subject_id' => $id,
            'causer_id' => $this->admin->id,
        ]);

        $entry = $this->latestActivity();

        // The stamping observer widens the change set (updated_at,
        // updated_by); the trail must keep the business change exact.
        $this->assertSame('Otra', $this->attribute($entry, 'old', 'name'));
        $this->assertSame('Otra raza', $this->attribute($entry, 'attributes', 'name'));
        $this->assertSame($this->admin->id, $this->attribute($entry, 'attributes', 'updated_by'));
    }

    public function test_soft_delete_and_restore_are_both_audited(): void
    {
        $id = Race::query()->create(['name' => 'Blanca'])->id;

        $this->deleteJson("/api/v1/catalogs/races/{$id}")->assertOk();
        $this->assertDatabaseHas('activity_log', [
            'event' => 'deleted',
            'subject_type' => Race::class,
            'subject_id' => $id,
        ]);

        $this->postJson("/api/v1/catalogs/races/{$id}/restore")->assertOk();
        $this->assertDatabaseHas('activity_log', [
            'event' => 'restored',
            'subject_type' => Race::class,
            'subject_id' => $id,
            'causer_id' => $this->admin->id,
        ]);

        $this->assertDatabaseHas('races', ['id' => $id, 'deleted_at' => null]);
    }

    public function test_restoring_an_active_entry_answers_409(): void
    {
        $id = Race::query()->create(['name' => 'Negra'])->id;

        $this->postJson("/api/v1/catalogs/races/{$id}/restore")
            ->assertConflict()
            ->assertJsonPath('message', 'The catalog entry is already active: only deactivated entries can be restored.');
    }

    public function test_restore_requires_admin_exclusivity(): void
    {
        $id = Race::query()->create(['name' => 'Blanca'])->id;
        $this->deleteJson("/api/v1/catalogs/races/{$id}")->assertOk();

        foreach (['director', 'specialist', 'operator', 'auditor'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->actingAs($user)
                ->postJson("/api/v1/catalogs/races/{$id}/restore")
                ->assertForbidden();
        }

        $this->actingAs($this->admin)
            ->postJson("/api/v1/catalogs/races/{$id}/restore")
            ->assertOk();
    }

    public function test_settings_writes_are_part_of_the_trail(): void
    {
        $this->postJson('/api/v1/general-settings', $this->settingsPayload())->assertCreated();

        $this->assertDatabaseHas('activity_log', [
            'event' => 'created',
            'causer_id' => $this->admin->id,
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function readersProvider(): array
    {
        return [
            'admin' => ['admin', 200],
            'auditor' => ['auditor', 200],
            'director' => ['director', 403],
            'specialist' => ['specialist', 403],
            'operator' => ['operator', 403],
        ];
    }

    #[DataProvider('readersProvider')]
    public function test_audit_index_answers_only_to_the_roles_the_matrix_grants(string $role, int $expected): void
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user)->getJson('/api/v1/audit-logs')->assertStatus($expected);
    }

    public function test_audit_index_filters_by_event_causer_and_subject(): void
    {
        $other = User::factory()->create();

        $first = Race::query()->create(['name' => 'Blanca']);
        Race::query()->create(['name' => 'Otra']);

        $this->patchJson("/api/v1/catalogs/races/{$first->id}", ['name' => 'Blanca editada'])->assertOk();

        // A write by another actor, to prove the causer filter works.
        $this->actingAs($other)->postJson('/api/v1/catalogs/races', ['code' => 'CHN', 'name' => 'China']);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/audit-logs?event=updated')
            ->assertOk();

        $rows = $response->json('data');
        $this->assertNotSame([], $rows);
        foreach ($rows as $row) {
            $this->assertSame('updated', $row['event']);
        }

        $byCauser = $this->getJson("/api/v1/audit-logs?causer_id={$this->admin->id}")
            ->assertOk()
            ->json('data');
        $this->assertNotSame([], $byCauser);
        foreach ($byCauser as $row) {
            $this->assertSame($this->admin->id, $row['causer_id']);
        }

        $bySubject = $this->getJson('/api/v1/audit-logs?subject_type='.urlencode(Race::class))
            ->assertOk()
            ->json('data');
        $this->assertNotSame([], $bySubject);
        foreach ($bySubject as $row) {
            $this->assertSame(Race::class, $row['subject_type']);
        }
    }

    public function test_audit_index_rejects_unknown_events_with_422(): void
    {
        $this->getJson('/api/v1/audit-logs?event=teleported')->assertUnprocessable();
    }

    public function test_the_trail_is_append_only_no_mutation_route_exists(): void
    {
        // POST on an existing read-only path answers 405 (method not
        // allowed); the per-id verbs answer 404 because no such route
        // is ever registered. Either way: no mutation surface exists.
        $this->postJson('/api/v1/audit-logs')->assertStatus(405);
        $this->putJson('/api/v1/audit-logs/1')->assertNotFound();
        $this->patchJson('/api/v1/audit-logs/1')->assertNotFound();
        $this->deleteJson('/api/v1/audit-logs/1')->assertNotFound();
    }

    public function test_csv_export_answers_to_audit_export_roles(): void
    {
        $this->postJson('/api/v1/catalogs/races', ['code' => 'CHN', 'name' => 'China'])->assertCreated();

        $response = $this->getJson('/api/v1/audit-logs/export');
        $response->assertOk();
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));

        $body = $response->streamedContent();
        $this->assertStringContainsString('id,event,causer_id', $body);

        // A data row (not the header, whose created_at also matches
        // 'created'): the event column of the write performed above.
        $this->assertStringContainsString(',created,', $body);

        $auditor = User::factory()->create();
        $auditor->assignRole('auditor');
        $this->actingAs($auditor)->getJson('/api/v1/audit-logs/export')->assertOk();

        $specialist = User::factory()->create();
        $specialist->assignRole('specialist');
        $this->actingAs($specialist)->getJson('/api/v1/audit-logs/export')->assertForbidden();
    }

    private function latestActivity(): Activity
    {
        $entry = Activity::query()->latest('id')->first();

        $this->assertNotNull($entry, 'The write must land in the activity trail.');

        return $entry;
    }

    private function property(Activity $entry, string $key): mixed
    {
        $properties = $entry->properties;
        $this->assertNotNull($properties);

        return $properties->get($key);
    }

    private function attribute(Activity $entry, string $group, string $key): mixed
    {
        $group = $this->property($entry, $group);
        $this->assertIsArray($group);

        return $group[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsPayload(): array
    {
        return [
            'min_work_years' => 25,
            'min_age_men' => 60,
            'min_age_women' => 55,
            'base_calc_percent' => 50,
            'max_calc_percent' => 90,
            'annual_increase_percent' => 1,
            'effective_from' => '2030-01-01',
        ];
    }
}
