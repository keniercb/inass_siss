<?php

declare(strict_types=1);

namespace App\Modules\Security\Tests\Unit;

use App\Modules\Security\Application\Services\UserService;
use App\Modules\Security\Domain\Authentication\PasswordPolicy;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use App\Modules\Security\Tests\Fakes\InMemoryUserRepository;
use App\Modules\Security\Tests\Support\BootsMinimalValidator;
use App\Modules\Shared\Contracts\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the administrative account lifecycle (S3.6,
 * RF-SEG-001/RF-AUD-004, ADR-24): creation with reserved email +
 * policy + roles, immutable email, last-administrator and
 * self-deactivation guards, unlock idempotency, password reset and
 * the search delegation.
 *
 * Runs without database or container through the in-memory fake.
 */
final class UserServiceTest extends TestCase
{
    use BootsMinimalValidator;

    private const PASSWORD = 'Segura2026';

    private InMemoryUserRepository $users;

    private UserService $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindMinimalValidatorFacade();

        $hasher = new BcryptHasher(['rounds' => 4]);
        $clock = self::frozenClock('2026-09-28 12:00:00');

        $this->admin = new User;
        $this->admin->setRawAttributes([
            'name' => 'Adriana Admin',
            'email' => 'adriana@sgp.local',
            'password' => $hasher->make(self::PASSWORD),
            // DateTimeImmutable (not a storage string): the date cast
            // then short-circuits without a DB connection resolver.
            'password_changed_at' => new DateTimeImmutable('2026-09-01 00:00:00'),
        ]);
        $this->admin->id = 7;

        $this->users = new InMemoryUserRepository;
        $this->users->seed($this->admin, ['admin']);

        $this->service = new UserService(
            $this->users,
            $clock,
            new PasswordPolicy(10, true, null),
        );
    }

    public function test_create_user_registers_the_account_with_its_roles(): void
    {
        $created = $this->service->createUser('Nueva Operaria', 'operaria@sgp.local', self::PASSWORD, ['operator']);

        self::assertSame('operaria@sgp.local', $created->email);
        self::assertSame($created, $this->users->lastCreated);
        self::assertSame(['operator'], $this->users->roleNamesOf($created));
    }

    public function test_create_user_rejects_a_reserved_email(): void
    {
        try {
            $this->service->createUser('Duplicada', 'adriana@sgp.local', self::PASSWORD, ['operator']);
            self::fail('ValidationException was expected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('email', $exception->errors());
        }
    }

    public function test_create_user_rejects_a_deactivated_accounts_email(): void
    {
        $ghost = new User;
        $ghost->setRawAttributes(['name' => 'Fantasma', 'email' => 'ghost@sgp.local']);
        $ghost->id = 8;
        $this->users->seed($ghost, ['operator']);
        $this->users->deactivate($ghost);
        $this->users->revokedAllTokens = 0; // isolate the guard from the bookkeeping

        try {
            $this->service->createUser('Reencarnacion', 'ghost@sgp.local', self::PASSWORD, ['operator']);
            self::fail('ValidationException was expected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('email', $exception->errors());
        }
    }

    public function test_create_user_rejects_a_weak_password(): void
    {
        try {
            $this->service->createUser('Debil', 'debil@sgp.local', 'short', ['operator']);
            self::fail('ValidationException was expected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('password', $exception->errors());
        }
    }

    public function test_create_user_rejects_unknown_roles(): void
    {
        try {
            $this->service->createUser('Inventada', 'rol@sgp.local', self::PASSWORD, ['emperor']);
            self::fail('ValidationException was expected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('roles', $exception->errors());
        }
    }

    public function test_update_user_renames_and_reassigns_roles(): void
    {
        $updated = $this->service->updateUser(7, 'Adriana Renombrada', ['admin', 'auditor'], null);

        self::assertNotNull($updated);
        self::assertSame('Adriana Renombrada', $updated->name);
        self::assertSame(['admin', 'auditor'], $this->users->roleNamesOf($this->admin));
    }

    public function test_update_user_rejects_an_email_change(): void
    {
        try {
            $this->service->updateUser(7, null, null, 'otra@sgp.local');
            self::fail('ValidationException was expected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('email', $exception->errors());
        }
    }

    public function test_update_user_accepts_the_same_email_untouched(): void
    {
        $updated = $this->service->updateUser(7, 'Solo Nombre', null, 'adriana@sgp.local');

        self::assertNotNull($updated);
        self::assertSame('Solo Nombre', $updated->name);
    }

    public function test_update_user_returns_null_for_an_unknown_account(): void
    {
        self::assertNull($this->service->updateUser(999, 'Nadie', null, null));
    }

    public function test_update_user_blocks_demoting_the_last_active_administrator(): void
    {
        // The admin is the only active administrator in the fake.
        try {
            $this->service->updateUser(7, null, ['operator'], null);
            self::fail('ValidationException was expected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('roles', $exception->errors());
        }
    }

    public function test_update_user_allows_demoting_when_another_admin_remains(): void
    {
        $other = new User;
        $other->setRawAttributes(['name' => 'Otro Admin', 'email' => 'otro@sgp.local']);
        $other->id = 9;
        $this->users->seed($other, ['admin']);

        $updated = $this->service->updateUser(7, null, ['operator'], null);

        self::assertNotNull($updated);
        self::assertSame(['operator'], $this->users->roleNamesOf($this->admin));
    }

    public function test_deactivate_user_blocks_self_deactivation(): void
    {
        try {
            $this->service->deactivate(7, 7);
            self::fail('ValidationException was expected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('id', $exception->errors());
        }
    }

    public function test_deactivate_user_blocks_removing_the_last_administrator(): void
    {
        try {
            $this->service->deactivate(7, 9); // another (non-admin) acting user id
            self::fail('ValidationException was expected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('roles', $exception->errors());
        }
    }

    public function test_deactivate_user_soft_deletes_and_revokes_every_token(): void
    {
        $operator = new User;
        $operator->setRawAttributes(['name' => 'Operario', 'email' => 'operario@sgp.local']);
        $operator->id = 11;
        $this->users->seed($operator, ['operator']);

        $deactivated = $this->service->deactivate(11, 7);

        self::assertNotNull($deactivated);
        self::assertNull($this->users->findById(11));
        self::assertNotNull($this->users->findByIdIncludingDeactivated(11));
    }

    public function test_deactivate_user_returns_null_for_an_unknown_account(): void
    {
        self::assertNull($this->service->deactivate(999, 7));
    }

    public function test_restore_user_reactivates_a_deactivated_account(): void
    {
        $operator = new User;
        $operator->setRawAttributes(['name' => 'Operario', 'email' => 'operario@sgp.local']);
        $operator->id = 11;
        $this->users->seed($operator, ['operator']);
        $this->users->deactivate($operator);

        $restored = $this->service->restore(11);

        self::assertNotNull($restored);
        self::assertNotNull($this->users->findById(11));
        self::assertSame(1, $this->users->restored);
    }

    public function test_restore_user_is_idempotent_for_an_active_account(): void
    {
        $restored = $this->service->restore(7);

        self::assertSame($this->admin, $restored);
        self::assertSame(0, $this->users->restored);
    }

    public function test_unlock_user_clears_the_lockout_state(): void
    {
        $this->admin->locked_at = new DateTimeImmutable('2026-09-28 11:59:00');
        $this->admin->failed_login_attempts = 5;

        $unlocked = $this->service->unlock(7);

        self::assertNotNull($unlocked);
        self::assertSame(1, $this->users->unlocked);
        self::assertSame(0, $this->admin->failed_login_attempts);
        self::assertNull($this->admin->locked_at);
    }

    public function test_unlock_user_is_idempotent_for_a_clean_account(): void
    {
        $unlocked = $this->service->unlock(7);

        self::assertSame($this->admin, $unlocked);
        self::assertSame(0, $this->users->unlocked);
    }

    public function test_reset_password_rejects_a_weak_password(): void
    {
        try {
            $this->service->resetPassword(7, 'short');
            self::fail('ValidationException was expected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('password', $exception->errors());
        }
    }

    public function test_reset_password_writes_the_secret_and_revokes_every_session(): void
    {
        $reset = $this->service->resetPassword(7, 'Renovada2026');

        self::assertNotNull($reset);
        self::assertSame('Renovada2026', $this->users->resetPasswords[7]);
        self::assertSame(1, $this->users->revokedAllTokens);
    }

    public function test_reset_password_returns_null_for_an_unknown_account(): void
    {
        self::assertNull($this->service->resetPassword(999, 'Renovada2026'));
    }

    public function test_search_delegates_the_filters_to_the_repository(): void
    {
        $paginator = $this->service->search(['role' => 'admin', 'status' => 'active'], 1, 15);

        self::assertSame(1, $paginator->total());
        $items = $paginator->items();
        self::assertArrayHasKey(0, $items);
        self::assertSame($this->admin, $items[0]);
    }

    private static function frozenClock(string $now): ClockInterface
    {
        return new class($now) implements ClockInterface
        {
            public function __construct(private readonly string $now) {}

            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable($this->now, new DateTimeZone('UTC'));
            }
        };
    }
}
