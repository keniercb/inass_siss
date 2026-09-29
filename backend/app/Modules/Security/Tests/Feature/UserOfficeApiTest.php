<?php

declare(strict_types=1);

namespace App\Modules\Security\Tests\Feature;

use App\Modules\Catalogs\Infrastructure\Persistence\Models\Municipality;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\OfficeType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Province;
use App\Modules\Organizations\Infrastructure\Persistence\Models\Office;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Users belong to an office (RF-SEG-001 territorial scope, ADR-29):
 * the account carries an optional office_id validated against the
 * ACTIVE office directory, the management surface (store/update)
 * assigns and reassigns it, an explicit null clears it and /auth/me
 * exposes the office of the authenticated user — the groundwork the
 * Sprint 6 territorial filtering will consume.
 *
 * The office deactivation guard closes the loop: an office with
 * active users assigned refuses to disappear (422 conversational,
 * same doctrine as the active-children guard of ADR-22), so no user
 * silently loses their office.
 */
final class UserOfficeApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Office $holguin;

    private Office $santiago;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->actingAsRole('admin');

        $provinceHolguin = Province::query()->create(['code' => '12', 'name' => 'Holguín']);
        $municipalityHolguin = Municipality::query()->create([
            'province_id' => $provinceHolguin->id, 'code' => '01', 'name' => 'Holguín',
        ]);
        $provinceSantiago = Province::query()->create(['code' => '14', 'name' => 'Santiago de Cuba']);
        $municipalitySantiago = Municipality::query()->create([
            'province_id' => $provinceSantiago->id, 'code' => '22', 'name' => 'Santiago de Cuba',
        ]);
        $municipal = OfficeType::query()->create(['code' => 'MUN', 'name' => 'Municipal']);

        $this->holguin = Office::query()->create([
            'office_type_id' => $municipal->id,
            'province_id' => $provinceHolguin->id,
            'municipality_id' => $municipalityHolguin->id,
            'address' => 'Calle Martí #100, Holguín',
        ]);
        $this->santiago = Office::query()->create([
            'office_type_id' => $municipal->id,
            'province_id' => $provinceSantiago->id,
            'municipality_id' => $municipalitySantiago->id,
            'address' => 'Calle Heredia #5, Santiago de Cuba',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'María Operadora',
            'email' => 'maria@sgp.local',
            'password' => 'Segura2026',
            'roles' => ['operator'],
        ], $overrides);
    }

    public function test_creates_a_user_belonging_to_an_office(): void
    {
        $this->postJson('/api/v1/users', $this->payload([
            'office_id' => $this->holguin->id,
        ]))->assertCreated()
            ->assertJsonPath('data.office.id', $this->holguin->id)
            ->assertJsonPath('data.office.address', 'Calle Martí #100, Holguín')
            ->assertJsonPath('data.office.type.code', 'MUN');

        $this->assertDatabaseHas('users', [
            'email' => 'maria@sgp.local',
            'office_id' => $this->holguin->id,
        ]);
    }

    public function test_creates_a_user_without_an_office(): void
    {
        $this->postJson('/api/v1/users', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.office', null);

        $this->assertDatabaseHas('users', [
            'email' => 'maria@sgp.local',
            'office_id' => null,
        ]);
    }

    public function test_rejects_an_unknown_office(): void
    {
        $this->postJson('/api/v1/users', $this->payload([
            'office_id' => 999999,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('office_id');
    }

    public function test_rejects_a_deactivated_office(): void
    {
        $this->santiago->delete();

        $this->postJson('/api/v1/users', $this->payload([
            'office_id' => $this->santiago->id,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('office_id');
    }

    public function test_reassigns_the_office(): void
    {
        $id = $this->postJson('/api/v1/users', $this->payload([
            'office_id' => $this->holguin->id,
        ]))->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/users/{$id}", [
            'office_id' => $this->santiago->id,
        ])->assertOk()
            ->assertJsonPath('data.office.id', $this->santiago->id);

        $this->assertDatabaseHas('users', [
            'id' => $id,
            'office_id' => $this->santiago->id,
        ]);
    }

    public function test_clears_the_office_with_an_explicit_null(): void
    {
        $id = $this->postJson('/api/v1/users', $this->payload([
            'office_id' => $this->holguin->id,
        ]))->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/users/{$id}", [
            'office_id' => null,
        ])->assertOk()
            ->assertJsonPath('data.office', null);

        $this->assertDatabaseHas('users', [
            'id' => $id,
            'office_id' => null,
        ]);
    }

    public function test_keeps_the_office_when_the_key_is_absent(): void
    {
        $id = $this->postJson('/api/v1/users', $this->payload([
            'office_id' => $this->holguin->id,
        ]))->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/users/{$id}", [
            'name' => 'María Reubicada',
        ])->assertOk()
            ->assertJsonPath('data.name', 'María Reubicada')
            ->assertJsonPath('data.office.id', $this->holguin->id);
    }

    public function test_office_changes_land_in_the_audit_trail(): void
    {
        $id = $this->postJson('/api/v1/users', $this->payload([
            'office_id' => $this->holguin->id,
        ]))->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/users/{$id}", [
            'office_id' => $this->santiago->id,
        ])->assertOk();

        $entry = Activity::query()
            ->where('subject_type', User::class)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($this->holguin->id, $entry->properties['old']['office_id'] ?? null);
        $this->assertSame($this->santiago->id, $entry->properties['attributes']['office_id'] ?? null);
    }

    public function test_me_exposes_the_user_office(): void
    {
        DB::table('users')->where('id', $this->admin->id)->update([
            'office_id' => $this->holguin->id,
        ]);

        // Same contract as the person link: the guard holds the
        // in-memory instance, refresh() surfaces the persisted office.
        $this->admin->refresh();

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $this->admin->email)
            ->assertJsonPath('data.office.id', $this->holguin->id)
            ->assertJsonPath('data.office.address', 'Calle Martí #100, Holguín')
            ->assertJsonPath('data.office.type.code', 'MUN')
            ->assertJsonPath('data.office.province.name', 'Holguín')
            ->assertJsonPath('data.office.municipality.name', 'Holguín');
    }

    public function test_me_returns_a_null_office_when_unassigned(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.office', null);
    }

    public function test_refuses_to_deactivate_an_office_with_active_users(): void
    {
        $this->postJson('/api/v1/users', $this->payload([
            'office_id' => $this->holguin->id,
        ]))->assertCreated();

        $this->deleteJson("/api/v1/offices/{$this->holguin->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('office_id');

        $this->assertDatabaseHas('offices', [
            'id' => $this->holguin->id,
            'deleted_at' => null,
        ]);
    }

    public function test_deactivates_the_office_once_users_are_reassigned(): void
    {
        $id = $this->postJson('/api/v1/users', $this->payload([
            'office_id' => $this->holguin->id,
        ]))->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/users/{$id}", [
            'office_id' => $this->santiago->id,
        ])->assertOk();

        $this->deleteJson("/api/v1/offices/{$this->holguin->id}")
            ->assertOk();

        $this->getJson("/api/v1/offices/{$this->holguin->id}")->assertNotFound();
    }

    public function test_a_deactivated_user_no_longer_blocks_the_office(): void
    {
        $user = User::factory()->create(['office_id' => $this->holguin->id]);
        $user->assignRole('operator');

        $user->delete();

        $this->deleteJson("/api/v1/offices/{$this->holguin->id}")
            ->assertOk();
    }
}
