<?php

declare(strict_types=1);

namespace App\Modules\Security\Tests\Feature;

use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Administrative user management (S3.6, RF-SEG-001, RF-AUD-004,
 * ADR-24): account directory with derived security state, creation
 * with password policy and role assignment, immutable email,
 * deactivation/restore with session revocation, brute-force lockout
 * with administrative unlock, password expiry (optional) and the
 * password lifecycle (admin reset + self renewal) — with secrets
 * redacted in the bitácora.
 */
final class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->actingAsRole('admin');
    }

    // ------------------------------------------------------------------
    // Directory (users.view)
    // ------------------------------------------------------------------

    public function test_index_lists_accounts_with_the_derived_security_state(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operator');

        $this->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonFragment(['email' => $this->admin->email, 'status' => 'active'])
            ->assertJsonFragment(['email' => $operator->email, 'status' => 'active']);

        // Derived state with the default configuration: nothing locked,
        // nothing expired, the password never leaves the storage.
        $this->getJson('/api/v1/users/'.$operator->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.locked', false)
            ->assertJsonPath('data.locked_until', null)
            ->assertJsonPath('data.failed_login_attempts', 0)
            ->assertJsonPath('data.password_expired', false)
            ->assertJsonMissingPath('data.password');
    }

    public function test_index_filters_by_text_role_and_status(): void
    {
        $adriana = User::factory()->create(['name' => 'Adriana Operaria', 'email' => 'adriana@sgp.local']);
        $adriana->assignRole('operator');
        $bernardo = User::factory()->create(['name' => 'Bernardo Especialista', 'email' => 'bernardo@sgp.local']);
        $bernardo->assignRole('specialist');

        // Text narrows over name and email (AND over words).
        $this->getJson('/api/v1/users?q=adriana')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.email', 'adriana@sgp.local');

        $this->getJson('/api/v1/users?q=sgp.local%20bernardo')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.email', 'bernardo@sgp.local');

        // Exact role membership.
        $this->getJson('/api/v1/users?role=specialist')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.email', 'bernardo@sgp.local');

        // Status: deactivated accounts appear only when asked for.
        $this->patchJson("/api/v1/users/{$bernardo->id}", ['name' => 'Bernardo Ex']);
        $this->deleteJson("/api/v1/users/{$bernardo->id}")->assertNoContent();

        $this->getJson('/api/v1/users')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/users?status=inactive')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.email', 'bernardo@sgp.local')
            ->assertJsonPath('data.0.status', 'inactive');
        $this->getJson('/api/v1/users?status=all')->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_show_answers_404_for_deactivated_or_unknown_accounts(): void
    {
        $ghost = User::factory()->create();
        $this->deleteJson("/api/v1/users/{$ghost->id}")->assertNoContent();

        $this->getJson('/api/v1/users/'.$ghost->id)->assertNotFound();
        $this->getJson('/api/v1/users/99999')->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Creation (users.manage)
    // ------------------------------------------------------------------

    public function test_store_creates_a_working_account_with_roles(): void
    {
        $response = $this->postJson('/api/v1/users', [
            'name' => 'María Operadora',
            'email' => 'maria@sgp.local',
            'password' => 'Segura2026',
            'roles' => ['operator'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.email', 'maria@sgp.local')
            ->assertJsonPath('data.roles', ['operator'])
            ->assertJsonPath('data.status', 'active');

        // The secret is stored hashed and the account can log in with
        // the plain-text value issued here (end-to-end hashing).
        $this->assertDatabaseHas('users', ['email' => 'maria@sgp.local']);
        $stored = User::where('email', 'maria@sgp.local')->first();
        self::assertInstanceOf(User::class, $stored);
        self::assertNotSame('Segura2026', $stored->password);
        self::assertNotNull($stored->password_changed_at);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'maria@sgp.local',
            'password' => 'Segura2026',
        ])->assertOk()->assertJsonPath('data.user.email', 'maria@sgp.local');
    }

    public function test_store_rejects_a_taken_email_with_a_semantic_422(): void
    {
        $this->postJson('/api/v1/users', [
            'name' => 'Duplicada',
            'email' => $this->admin->email,
            'password' => 'Segura2026',
            'roles' => ['operator'],
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_store_rejects_the_email_of_a_deactivated_account(): void
    {
        $ghost = User::factory()->create(['email' => 'ghost@sgp.local']);
        $this->deleteJson("/api/v1/users/{$ghost->id}")->assertNoContent();

        $this->postJson('/api/v1/users', [
            'name' => 'Reencarnación',
            'email' => 'ghost@sgp.local',
            'password' => 'Segura2026',
            'roles' => ['operator'],
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    #[DataProvider('weakPasswordProvider')]
    public function test_store_rejects_passwords_that_violate_the_policy(string $password): void
    {
        $this->postJson('/api/v1/users', [
            'name' => 'Débil',
            'email' => 'debil@sgp.local',
            'password' => $password,
            'roles' => ['operator'],
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    /** @return array<string, array{0: string}> */
    public static function weakPasswordProvider(): array
    {
        return [
            'too short' => ['Ab1'],
            'missing digit' => ['SoloLetrasMayusculas'],
            'missing lowercase' => ['SOLOMAYUSCULAS1'],
            'missing uppercase' => ['solominusculas1'],
        ];
    }

    public function test_store_rejects_unknown_roles(): void
    {
        $this->postJson('/api/v1/users', [
            'name' => 'Inventada',
            'email' => 'inventada@sgp.local',
            'password' => 'Segura2026',
            'roles' => ['emperador'],
        ])->assertUnprocessable()->assertJsonValidationErrors('roles.0');
    }

    // ------------------------------------------------------------------
    // Edition (users.manage)
    // ------------------------------------------------------------------

    public function test_update_renames_and_replaces_the_role_assignment(): void
    {
        $target = User::factory()->create();
        $target->assignRole('operator');

        $this->patchJson("/api/v1/users/{$target->id}", [
            'name' => 'María Especialista',
            'roles' => ['specialist', 'auditor'],
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'María Especialista')
            ->assertJsonPath('data.roles', ['auditor', 'specialist']);

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'name' => 'María Especialista',
        ]);
    }

    public function test_update_rejects_an_email_change(): void
    {
        $target = User::factory()->create(['email' => 'original@sgp.local']);

        $this->patchJson("/api/v1/users/{$target->id}", [
            'email' => 'cambiado@sgp.local',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        // The same email travels fine (read-modify-write clients).
        $this->patchJson("/api/v1/users/{$target->id}", [
            'email' => 'original@sgp.local',
            'name' => 'Sin Cambio De Clave',
        ])->assertOk();
    }

    public function test_update_blocks_demoting_the_only_active_administrator(): void
    {
        $this->patchJson("/api/v1/users/{$this->admin->id}", [
            'roles' => ['operator'],
        ])->assertUnprocessable()->assertJsonValidationErrors('roles');

        $this->admin->refresh();
        self::assertTrue($this->admin->hasRole('admin'), 'The demotion must not be applied.');
    }

    public function test_role_changes_land_in_the_bitacora_with_previous_values(): void
    {
        $target = User::factory()->create();
        $target->assignRole('operator');

        $this->patchJson("/api/v1/users/{$target->id}", ['roles' => ['specialist']]);

        $roleEntries = Activity::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $target->id)
            ->where('event', 'updated')
            ->where('properties->old', 'like', '%roles%')
            ->get();

        self::assertCount(1, $roleEntries);
        $entry = $roleEntries[0] ?? null;
        self::assertNotNull($entry);
        $properties = $entry->properties;
        self::assertNotNull($properties);
        self::assertSame(['operator'], $properties['old']['roles'] ?? null);
        self::assertSame(['specialist'], $properties['attributes']['roles'] ?? null);
        self::assertSame($this->admin->id, $entry->causer_id);
    }

    // ------------------------------------------------------------------
    // Deactivation / restoration (RF-AUD-004)
    // ------------------------------------------------------------------

    public function test_deactivate_blocks_self_deactivation(): void
    {
        $this->deleteJson('/api/v1/users/'.$this->admin->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id');
    }

    public function test_deactivate_blocks_removing_the_last_administrator(): void
    {
        // Only admin in the system: removal would leave no account able
        // to manage anything.
        $this->deleteJson('/api/v1/users/'.$this->admin->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id');
    }

    public function test_deactivate_soft_deletes_revokes_sessions_and_reserves_identity(): void
    {
        $target = User::factory()->create(['email' => 'target@sgp.local', 'password' => 'Segura2026']);
        $target->assignRole('operator');
        $token = $target->createToken('session')->plainTextToken;

        $this->deleteJson("/api/v1/users/{$target->id}")->assertNoContent();

        // Gone from the active surface...
        $this->getJson('/api/v1/users/'.$target->id)->assertNotFound();
        // ...cannot authenticate...
        $this->postJson('/api/v1/auth/login', [
            'email' => 'target@sgp.local',
            'password' => 'Segura2026',
        ])->assertUnauthorized();
        // ...and previously issued tokens die with the account.
        // forgetGuards() emulates a fresh PHP-FPM process (AuthTest
        // pattern): the acting admin must not leak into the token check.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();

        // Restoring reactivates the account with the same identity.
        $this->actingAs($this->admin);
        $this->postJson("/api/v1/users/{$target->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'target@sgp.local',
            'password' => 'Segura2026',
        ])->assertOk();
    }

    public function test_restore_is_idempotent_for_an_active_account(): void
    {
        $before = Activity::query()->where('event', 'restored')->count();

        $this->postJson("/api/v1/users/{$this->admin->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        self::assertSame($before, Activity::query()->where('event', 'restored')->count());
    }

    // ------------------------------------------------------------------
    // Brute-force lockout (RF-SEC-001)
    // ------------------------------------------------------------------

    public function test_five_failed_logins_lock_the_account_even_with_valid_credentials(): void
    {
        $target = User::factory()->create(['email' => 'locked@sgp.local', 'password' => 'Segura2026']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'locked@sgp.local',
                'password' => 'contraseña-equivocada',
            ])->assertUnauthorized();
        }

        // The credentials are correct, but the account is locked: the
        // response stays the generic 401 (the lock is not disclosed).
        $this->postJson('/api/v1/auth/login', [
            'email' => 'locked@sgp.local',
            'password' => 'Segura2026',
        ])->assertUnauthorized()->assertJsonPath('message', 'Invalid credentials.');

        // The directory shows the lock for the Administrator.
        $response = $this->getJson('/api/v1/users/'.$target->id);

        $response->assertOk()
            ->assertJsonPath('data.locked', true)
            ->assertJsonPath('data.failed_login_attempts', 5);
        self::assertNotNull($response->json('data.locked_until'));

        // The failure bookkeeping never reaches the bitácora.
        $noisy = Activity::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $target->id)
            ->where('properties->attributes', 'like', '%failed_login_attempts%')
            ->count();
        self::assertSame(0, $noisy);
    }

    public function test_the_administrator_unlocks_the_account_before_the_ttl(): void
    {
        $target = User::factory()->create(['email' => 'unlock@sgp.local', 'password' => 'Segura2026']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'unlock@sgp.local',
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        $this->postJson("/api/v1/users/{$target->id}/unlock")
            ->assertOk()
            ->assertJsonPath('data.locked', false)
            ->assertJsonPath('data.failed_login_attempts', 0)
            ->assertJsonPath('data.locked_until', null);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'unlock@sgp.local',
            'password' => 'Segura2026',
        ])->assertOk();

        // The unlock is an audited write with the previous lock state.
        $entries = Activity::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $target->id)
            ->where('event', 'updated')
            ->where('properties->old', 'like', '%locked_at%')
            ->count();
        self::assertSame(1, $entries);
    }

    public function test_a_stale_lock_auto_expires_by_the_calendar(): void
    {
        $target = User::factory()->create(['email' => 'stale@sgp.local', 'password' => 'Segura2026']);

        DB::table('users')->where('id', $target->id)->update([
            'locked_at' => now()->subMinutes(30),
            'failed_login_attempts' => 5,
        ]);

        // TTL is 15 minutes by default: the lock expired 15 ago.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'stale@sgp.local',
            'password' => 'Segura2026',
        ])->assertOk();

        $target->refresh();
        self::assertNull($target->locked_at);
        self::assertSame(0, $target->failed_login_attempts);
    }

    // ------------------------------------------------------------------
    // Password expiry (optional, RF-SEG-001 "caducidad opcional")
    // ------------------------------------------------------------------

    public function test_an_expired_password_rejects_login_with_a_renewal_message(): void
    {
        config(['security.password.max_age_days' => 90]);

        $target = User::factory()->create(['email' => 'aged@sgp.local', 'password' => 'Segura2026']);
        DB::table('users')->where('id', $target->id)->update([
            'password_changed_at' => now()->subDays(91),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'aged@sgp.local',
            'password' => 'Segura2026',
        ])->assertUnauthorized()->assertJsonPath('message', 'The password has expired and must be renewed.');
    }

    public function test_password_expiry_stays_off_by_default(): void
    {
        $target = User::factory()->create(['email' => 'fresh@sgp.local', 'password' => 'Segura2026']);
        DB::table('users')->where('id', $target->id)->update([
            'password_changed_at' => now()->subYears(3),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'fresh@sgp.local',
            'password' => 'Segura2026',
        ])->assertOk();
    }

    // ------------------------------------------------------------------
    // Password lifecycle: admin reset + self renewal
    // ------------------------------------------------------------------

    public function test_the_administrator_resets_a_password_and_revokes_every_session(): void
    {
        $target = User::factory()->create(['email' => 'reset@sgp.local', 'password' => 'Segura2026']);
        $target->assignRole('operator');
        $token = $target->createToken('session')->plainTextToken;

        $this->patchJson("/api/v1/users/{$target->id}/password", [
            'password' => 'Renovada2026',
        ])
            ->assertOk()
            ->assertJsonPath('data.password_expired', false);

        // The old secret is gone, the new one works...
        $this->postJson('/api/v1/auth/login', [
            'email' => 'reset@sgp.local',
            'password' => 'Segura2026',
        ])->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'reset@sgp.local',
            'password' => 'Renovada2026',
        ])->assertOk();

        // ...and every session issued before the reset died.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_the_reset_never_leaks_the_secret_into_the_bitacora(): void
    {
        $target = User::factory()->create(['email' => 'redacted@sgp.local', 'password' => 'Segura2026']);

        $this->patchJson("/api/v1/users/{$target->id}/password", [
            'password' => 'Renovada2026',
        ])->assertOk();

        $entries = Activity::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $target->id)
            ->where('event', 'updated')
            ->where('properties->attributes', 'like', '%password%')
            ->get();

        self::assertNotCount(0, $entries);

        foreach ($entries as $entry) {
            $properties = $entry->properties;
            self::assertNotNull($properties);
            self::assertSame('[redacted]', $properties['attributes']['password'] ?? null);
        }
    }

    public function test_a_password_reset_clears_an_active_lockout(): void
    {
        $target = User::factory()->create(['email' => 'lockedreset@sgp.local', 'password' => 'Segura2026']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'lockedreset@sgp.local',
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        $this->patchJson("/api/v1/users/{$target->id}/password", [
            'password' => 'Renovada2026',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'lockedreset@sgp.local',
            'password' => 'Renovada2026',
        ])->assertOk();
    }

    public function test_a_user_renews_their_own_password_keeping_the_running_session(): void
    {
        $target = User::factory()->create(['email' => 'self@sgp.local', 'password' => 'Segura2026']);
        $target->assignRole('operator');

        $loginA = $this->postJson('/api/v1/auth/login', [
            'email' => 'self@sgp.local', 'password' => 'Segura2026',
        ])->json('data.token');

        $loginB = $this->postJson('/api/v1/auth/login', [
            'email' => 'self@sgp.local', 'password' => 'Segura2026',
        ])->json('data.token');

        // Fresh process semantics: the token, not the acting admin of
        // the suite, authenticates this request.
        $this->app['auth']->forgetGuards();

        $this->withToken($loginA)->postJson('/api/v1/auth/password', [
            'current_password' => 'Segura2026',
            'password' => 'Renovada2026',
        ])->assertOk()->assertJsonPath('data.email', 'self@sgp.local');

        // The running session survives; the other one is revoked.
        $this->app['auth']->forgetGuards();
        $this->withToken($loginA)->getJson('/api/v1/auth/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($loginB)->getJson('/api/v1/auth/me')->assertUnauthorized();

        // The old secret no longer authenticates.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'self@sgp.local', 'password' => 'Segura2026',
        ])->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', [
            'email' => 'self@sgp.local', 'password' => 'Renovada2026',
        ])->assertOk();
    }

    public function test_self_renewal_rejects_a_wrong_current_password(): void
    {
        $target = User::factory()->create(['email' => 'wrongcur@sgp.local', 'password' => 'Segura2026']);
        $token = $target->createToken('session')->plainTextToken;

        $this->app['auth']->forgetGuards();

        $this->withToken($token)->postJson('/api/v1/auth/password', [
            'current_password' => 'not-the-current',
            'password' => 'Renovada2026',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }

    public function test_self_renewal_rejects_reusing_the_current_password(): void
    {
        $target = User::factory()->create(['email' => 'samepass@sgp.local', 'password' => 'Segura2026']);
        $token = $target->createToken('session')->plainTextToken;

        $this->app['auth']->forgetGuards();

        $this->withToken($token)->postJson('/api/v1/auth/password', [
            'current_password' => 'Segura2026',
            'password' => 'Segura2026',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    // ------------------------------------------------------------------
    // RBAC (RF-SEG-002)
    // ------------------------------------------------------------------

    public function test_the_directory_is_readable_by_admin_and_auditor_only(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operator');
        $auditor = User::factory()->create();
        $auditor->assignRole('auditor');
        $director = User::factory()->create();
        $director->assignRole('director');

        // Anonymous stays out (fresh process, no acting admin).
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/users')->assertUnauthorized();

        // Auditor reads (bitácora cross-reading), operator/director do
        // not (accounts are not their surface, sección 2.2).
        $this->actingAs($auditor)->getJson('/api/v1/users')->assertOk();
        $this->actingAs($operator)->getJson('/api/v1/users')->assertForbidden();
        $this->actingAs($director)->getJson('/api/v1/users')->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('manageEndpointProvider')]
    public function test_manage_endpoints_stay_exclusive_to_the_administrator(string $method, string $uri, array $payload): void
    {
        $auditor = User::factory()->create();
        $auditor->assignRole('auditor');

        $this->actingAs($auditor)->json($method, $uri, $payload)->assertForbidden();
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> */
    public static function manageEndpointProvider(): array
    {
        return [
            'store' => ['POST', '/api/v1/users', [
                'name' => 'X', 'email' => 'x@sgp.local', 'password' => 'Segura2026', 'roles' => ['operator'],
            ]],
            'update' => ['PATCH', '/api/v1/users/1', ['name' => 'X']],
            'destroy' => ['DELETE', '/api/v1/users/1', []],
            'restore' => ['POST', '/api/v1/users/1/restore', []],
            'unlock' => ['POST', '/api/v1/users/1/unlock', []],
            'reset password' => ['PATCH', '/api/v1/users/1/password', ['password' => 'Renovada2026']],
        ];
    }
}
