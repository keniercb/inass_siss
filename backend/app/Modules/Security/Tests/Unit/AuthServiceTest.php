<?php

declare(strict_types=1);

namespace App\Modules\Security\Tests\Unit;

use App\Modules\Security\Application\Exceptions\PasswordExpiredException;
use App\Modules\Security\Application\Services\AuthService;
use App\Modules\Security\Domain\Authentication\LockoutPolicy;
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
 * Unit tests for the authentication use cases (RF-SEG-001): login
 * with the brute-force lockout, the optional password expiry and the
 * self-service renewal.
 *
 * Runs without database or container: the repository port is
 * replaced by an in-memory fake, hashing uses the real bcrypt driver
 * at minimum cost and the clock is frozen, which keeps the suite fast
 * and the business rules verifiable in isolation.
 */
final class AuthServiceTest extends TestCase
{
    use BootsMinimalValidator;

    private const EMAIL = 'admin@sgp.local';

    private const PASSWORD = 'Segura2026';

    private User $user;

    private InMemoryUserRepository $users;

    private AuthService $service;

    /** Frozen at 2026-09-28 12:00:00 UTC. */
    private ClockInterface $clock;

    private BcryptHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindMinimalValidatorFacade();

        $this->hasher = new BcryptHasher(['rounds' => 4]);
        $this->clock = self::frozenClock('2026-09-28 12:00:00');

        $this->user = new User;
        $this->user->setRawAttributes([
            'name' => 'SGP Demo Admin',
            'email' => self::EMAIL,
            'password' => $this->hasher->make(self::PASSWORD),
            // DateTimeImmutable (not a storage string): the date cast
            // then short-circuits without a DB connection resolver.
            'password_changed_at' => new DateTimeImmutable('2026-09-01 00:00:00'),
        ]);
        $this->user->id = 1;

        $this->users = new InMemoryUserRepository;
        $this->users->seed($this->user, ['admin']);

        $this->service = new AuthService(
            $this->users,
            $this->hasher,
            $this->clock,
            new LockoutPolicy(5, 900),
            new PasswordPolicy(10, true, null),
        );
    }

    public function test_login_with_valid_credentials_returns_user_and_token(): void
    {
        $result = $this->service->login(self::EMAIL, self::PASSWORD);

        self::assertNotNull($result);
        self::assertSame($this->user, $result->user);
        self::assertSame('plain-text-token', $result->token);
        self::assertSame(1, $this->users->issuedTokens);
    }

    public function test_login_with_wrong_password_returns_null_and_issues_no_token(): void
    {
        self::assertNull($this->service->login(self::EMAIL, 'wrong-password'));
        self::assertSame(0, $this->users->issuedTokens);
    }

    public function test_login_with_unknown_email_returns_null_and_issues_no_token(): void
    {
        self::assertNull($this->service->login('ghost@sgp.local', self::PASSWORD));
        self::assertSame(0, $this->users->issuedTokens);
    }

    public function test_login_with_wrong_password_records_one_failure(): void
    {
        $this->service->login(self::EMAIL, 'wrong-password');

        self::assertCount(1, $this->users->recordedFailures);
        self::assertSame(1, $this->users->recordedFailures[0]->failedAttempts);
        self::assertNull($this->users->recordedFailures[0]->lockedAt);
    }

    public function test_login_with_unknown_email_records_no_failure(): void
    {
        // Anti-enumeration: no row exists, so nothing is counted.
        $this->service->login('ghost@sgp.local', self::PASSWORD);

        self::assertSame([], $this->users->recordedFailures);
    }

    public function test_locking_attempt_stamps_the_lock_instant(): void
    {
        // Four previous failures already counted on the account.
        $this->user->failed_login_attempts = 4;

        $this->service->login(self::EMAIL, 'wrong-password');

        self::assertCount(1, $this->users->recordedFailures);
        self::assertSame(5, $this->users->recordedFailures[0]->failedAttempts);
        self::assertEquals(new DateTimeImmutable('2026-09-28 12:00:00'), $this->users->recordedFailures[0]->lockedAt);
    }

    public function test_a_locked_account_is_rejected_without_counting(): void
    {
        $this->user->locked_at = new DateTimeImmutable('2026-09-28 11:59:00');

        self::assertNull($this->service->login(self::EMAIL, self::PASSWORD));
        self::assertSame(0, $this->users->issuedTokens);
        self::assertSame([], $this->users->recordedFailures);
    }

    public function test_a_stale_lock_auto_expires_and_login_succeeds(): void
    {
        // Locked at 11:00 with a 15-minute TTL: long expired.
        $this->user->locked_at = new DateTimeImmutable('2026-09-28 11:00:00');
        $this->user->failed_login_attempts = 5;

        $result = $this->service->login(self::EMAIL, self::PASSWORD);

        self::assertNotNull($result);
        self::assertSame(1, $this->users->clearedFailures);
    }

    public function test_successful_login_clears_previous_failures(): void
    {
        $this->user->failed_login_attempts = 3;
        $this->user->locked_at = null;

        $this->service->login(self::EMAIL, self::PASSWORD);

        self::assertSame(1, $this->users->clearedFailures);
    }

    public function test_login_with_an_expired_password_throws_and_issues_no_token(): void
    {
        $service = new AuthService(
            $this->users,
            $this->hasher,
            $this->clock,
            new LockoutPolicy(5, 900),
            new PasswordPolicy(10, true, 27), // changed 2026-09-01: 27 days lands exactly today
        );

        try {
            $service->login(self::EMAIL, self::PASSWORD);
            self::fail('PasswordExpiredException was expected.');
        } catch (PasswordExpiredException) {
            // expected: valid secrets, aged-out password
        }

        self::assertSame(0, $this->users->issuedTokens);
        // The failures were still cleaned on the valid credentials.
        self::assertSame(1, $this->users->clearedFailures);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $this->service->logout($this->user);

        self::assertTrue($this->users->revokedCurrentToken);
    }

    public function test_change_password_with_a_wrong_current_password_is_rejected(): void
    {
        try {
            $this->service->changePassword($this->user, 'not-the-current', 'OtraSegura2027');
            self::fail('ValidationException was expected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('current_password', $exception->errors());
        }

        self::assertSame([], $this->users->resetPasswords);
    }

    public function test_change_password_rejects_reusing_the_current_password(): void
    {
        try {
            $this->service->changePassword($this->user, self::PASSWORD, self::PASSWORD);
            self::fail('ValidationException was expected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('password', $exception->errors());
        }

        self::assertSame([], $this->users->resetPasswords);
    }

    public function test_change_password_applies_the_policy_to_the_new_password(): void
    {
        try {
            $this->service->changePassword($this->user, self::PASSWORD, 'weak');
            self::fail('ValidationException was expected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('password', $exception->errors());
        }
    }

    public function test_change_password_resets_the_secret_and_keeps_the_running_session(): void
    {
        $this->service->changePassword($this->user, self::PASSWORD, 'OtraSegura2027');

        self::assertSame('OtraSegura2027', $this->users->resetPasswords[1]);
        self::assertSame(1, $this->users->revokedOtherTokens);
        self::assertSame(0, $this->users->revokedAllTokens);
        self::assertEquals(
            new DateTimeImmutable('2026-09-28 12:00:00'),
            $this->user->password_changed_at,
        );
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
