<?php

declare(strict_types=1);

namespace App\Modules\Security\Tests\Feature;

use App\Modules\Security\Infrastructure\Authentication\AuthenticatedUserIdProvider;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use App\Modules\Shared\Contracts\CurrentUserProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Traceability of the users table (RF-AUD-004, ADR-14): the audit
 * columns exist, authorship is stamped automatically by the auditable
 * observer for both session and sanctum actors, and soft-deleted
 * accounts can no longer authenticate.
 */
final class AuditFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_carries_the_audit_columns(): void
    {
        self::assertTrue(Schema::hasColumn('users', 'created_by'));
        self::assertTrue(Schema::hasColumn('users', 'updated_by'));
        self::assertTrue(Schema::hasColumn('users', 'deleted_at'));
    }

    public function test_creation_stamps_the_authenticated_creator(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin);

        $created = User::factory()->create();

        self::assertSame($admin->id, $created->created_by);
        self::assertNull($created->updated_by);
        self::assertSame($admin->id, $created->creator?->id);
    }

    public function test_update_stamps_the_last_modifier(): void
    {
        $admin = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($admin);

        $target->update(['name' => 'Renamed']);

        self::assertSame($admin->id, $target->updated_by);
        self::assertSame($admin->id, $target->updater?->id);
    }

    public function test_anonymous_creation_leaves_authorship_empty(): void
    {
        $created = User::factory()->create();

        self::assertNull($created->created_by);
        self::assertNull($created->updated_by);
    }

    public function test_sanctum_guard_actor_is_stamped(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin, 'sanctum');

        $created = User::factory()->create();

        self::assertSame($admin->id, $created->created_by);
    }

    public function test_soft_deleted_users_cannot_authenticate(): void
    {
        $user = User::factory()->create();

        $user->delete();

        $this->assertSoftDeleted($user);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertUnauthorized();
    }

    /**
     * Wiring check (ADR-14): the container resolves the audit actor
     * port to the Security implementation, so observers only know the
     * Shared contract.
     */
    public function test_current_user_provider_contract_resolves_the_default_implementation(): void
    {
        self::assertInstanceOf(
            AuthenticatedUserIdProvider::class,
            $this->app->make(CurrentUserProviderInterface::class),
        );
    }
}
