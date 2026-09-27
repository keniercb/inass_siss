<?php

declare(strict_types=1);

namespace App\Modules\Security\Tests\Feature;

use App\Modules\People\Infrastructure\Persistence\Models\Person;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * User ↔ person association (S3.5, RF-SEG-004): an account links to a
 * registered person for action traceability. Uniqueness is a business
 * rule (409 conversational) and a database constraint (backstop), the
 * link/unlink writes land in the append-only audit trail (RF-AUD-001)
 * and /auth/me exposes the linked person summary so every consumer of
 * the session knows who the natural person behind the account is.
 */
final class UserPersonLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Admin holds users.manage (PermissionMatrix); every write goes
        // through it, role-specific denial has its own tests below.
        $this->admin = $this->actingAsRole('admin');
    }

    public function test_link_person_returns_the_user_with_the_person_summary(): void
    {
        $person = Person::factory()->create();

        $this->postJson("/api/v1/users/{$this->admin->id}/person", [
            'person_id' => $person->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $this->admin->id)
            ->assertJsonPath('data.person.id', $person->id)
            ->assertJsonPath('data.person.identity_number', $person->identity_number)
            ->assertJsonPath('data.person.full_name', 'Juan Carlos Pérez Gómez')
            ->assertJsonPath('data.person.deceased', false);
    }

    public function test_link_person_is_idempotent_for_the_same_user(): void
    {
        $person = Person::factory()->create();

        $this->postJson("/api/v1/users/{$this->admin->id}/person", [
            'person_id' => $person->id,
        ])->assertOk();

        // Relinking the same person to the same account is not a
        // conflict: the association is already exactly what was asked.
        $this->postJson("/api/v1/users/{$this->admin->id}/person", [
            'person_id' => $person->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.person.id', $person->id);
    }

    public function test_link_person_rejects_a_person_already_linked_to_another_user(): void
    {
        $person = Person::factory()->create();
        $owner = User::factory()->create();

        DB::table('users')->where('id', $owner->id)->update(['person_id' => $person->id]);

        $this->postJson("/api/v1/users/{$this->admin->id}/person", [
            'person_id' => $person->id,
        ])
            ->assertConflict()
            ->assertJsonPath('message', 'The person is already linked to another user.')
            ->assertJsonPath('person_id', $person->id)
            ->assertJsonPath('linked_to_user_id', $owner->id);
    }

    public function test_link_person_rejects_an_unknown_person(): void
    {
        $this->postJson("/api/v1/users/{$this->admin->id}/person", [
            'person_id' => 999999,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['person_id']);
    }

    public function test_link_person_requires_an_existing_user(): void
    {
        $person = Person::factory()->create();

        $this->postJson('/api/v1/users/999999/person', [
            'person_id' => $person->id,
        ])->assertNotFound();
    }

    public function test_link_person_requires_the_users_manage_permission(): void
    {
        $person = Person::factory()->create();

        $this->actingAsRole('operator');

        $this->postJson("/api/v1/users/{$this->admin->id}/person", [
            'person_id' => $person->id,
        ])->assertForbidden();
    }

    public function test_me_exposes_the_linked_person_summary(): void
    {
        $person = Person::factory()->create([
            'death_date' => '2020-01-01',
        ]);

        DB::table('users')->where('id', $this->admin->id)->update(['person_id' => $person->id]);

        // The guard holds the in-memory instance injected by actingAs;
        // refresh() mutates it in place so /me sees the persisted link,
        // exactly as a fresh request would after resolving the token.
        $this->admin->refresh();

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $this->admin->email)
            ->assertJsonPath('data.roles.0', 'admin')
            ->assertJsonPath('data.person.id', $person->id)
            ->assertJsonPath('data.person.deceased', true);
    }

    public function test_me_returns_a_null_person_when_not_linked(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.person', null);
    }

    public function test_unlink_person_clears_the_association(): void
    {
        $person = Person::factory()->create();

        DB::table('users')->where('id', $this->admin->id)->update(['person_id' => $person->id]);

        $this->deleteJson("/api/v1/users/{$this->admin->id}/person")
            ->assertOk()
            ->assertJsonPath('data.person', null);

        $this->assertDatabaseHas('users', [
            'id' => $this->admin->id,
            'person_id' => null,
        ]);
    }

    public function test_unlink_person_is_idempotent_when_no_person_is_linked(): void
    {
        $this->deleteJson("/api/v1/users/{$this->admin->id}/person")
            ->assertOk()
            ->assertJsonPath('data.person', null);
    }

    public function test_unlink_person_requires_the_users_manage_permission(): void
    {
        $this->actingAsRole('auditor');

        $this->deleteJson("/api/v1/users/{$this->admin->id}/person")
            ->assertForbidden();
    }

    public function test_link_and_unlink_land_in_the_audit_trail_with_previous_values(): void
    {
        $person = Person::factory()->create();

        $this->postJson("/api/v1/users/{$this->admin->id}/person", [
            'person_id' => $person->id,
        ])->assertOk();

        $linkActivity = Activity::query()
            ->where('subject_type', (new User)->getMorphClass())
            ->where('subject_id', $this->admin->id)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        self::assertNotNull($linkActivity, 'The link write must land in the audit trail.');
        self::assertSame($this->admin->id, $linkActivity->causer_id);
        self::assertNull($linkActivity->properties['old']['person_id'] ?? null);
        self::assertSame((string) $person->id, (string) ($linkActivity->properties['attributes']['person_id'] ?? null));

        $this->deleteJson("/api/v1/users/{$this->admin->id}/person")->assertOk();

        $unlinkActivity = Activity::query()
            ->where('subject_type', (new User)->getMorphClass())
            ->where('subject_id', $this->admin->id)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        self::assertNotNull($unlinkActivity, 'The unlink write must land in the audit trail.');
        self::assertSame((string) $person->id, (string) ($unlinkActivity->properties['old']['person_id'] ?? null));
        self::assertNull($unlinkActivity->properties['attributes']['person_id'] ?? null);
    }

    public function test_the_database_rejects_two_accounts_for_one_person(): void
    {
        $person = Person::factory()->create();

        DB::table('users')->where('id', $this->admin->id)->update(['person_id' => $person->id]);

        $other = User::factory()->create();

        $this->expectException(QueryException::class);

        $other->forceFill(['person_id' => $person->id])->save();
    }
}
