<?php

declare(strict_types=1);

namespace App\Modules\Security\Tests\Unit;

use App\Modules\Security\Application\Services\AuthService;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use App\Modules\Security\Tests\Fakes\InMemoryUserRepository;
use Illuminate\Hashing\BcryptHasher;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the authentication use cases (RF-SEG-001).
 *
 * Runs without database or container: the repository port is
 * replaced by an in-memory fake and hashing uses the real bcrypt
 * driver at minimum cost, which keeps the suite fast and the
 * business rules verifiable in isolation.
 */
final class AuthServiceTest extends TestCase
{
    private const EMAIL = 'admin@sgp.local';

    private const PASSWORD = 'password';

    private User $user;

    private InMemoryUserRepository $users;

    private AuthService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $hasher = new BcryptHasher(['rounds' => 4]);

        $this->user = new User;
        $this->user->setRawAttributes([
            'name' => 'SGP Demo Admin',
            'email' => self::EMAIL,
            'password' => $hasher->make(self::PASSWORD),
        ]);

        $this->users = new InMemoryUserRepository;
        $this->users->stored = $this->user;

        $this->service = new AuthService(
            $this->users,
            $hasher,
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

    public function test_logout_revokes_the_current_token(): void
    {
        $this->service->logout($this->user);

        self::assertTrue($this->users->revokedCurrentToken);
    }
}
