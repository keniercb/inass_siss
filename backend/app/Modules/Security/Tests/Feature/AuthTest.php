<?php

declare(strict_types=1);

namespace App\Modules\Security\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 0 login skeleton acceptance (RF-SEG-001):
 * token issuance, credential rejection and token revocation.
 */
final class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const DEMO_EMAIL = 'admin@sgp.local';

    private const DEMO_PASSWORD = 'password';

    protected function setUp(): void
    {
        parent::setUp();

        User::firstOrCreate(
            ['email' => self::DEMO_EMAIL],
            ['name' => 'SGP Demo Admin', 'password' => self::DEMO_PASSWORD],
        );
    }

    public function test_login_issues_a_bearer_token(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => self::DEMO_EMAIL,
            'password' => self::DEMO_PASSWORD,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', self::DEMO_EMAIL);

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_rejects_wrong_password(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => self::DEMO_EMAIL,
            'password' => 'wrong-password',
        ])->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid credentials.');
    }

    public function test_login_validates_the_payload(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'not-an-email',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => self::DEMO_EMAIL,
            'password' => self::DEMO_PASSWORD,
        ])->json('data.token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', self::DEMO_EMAIL);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => self::DEMO_EMAIL,
            'password' => self::DEMO_PASSWORD,
        ])->json('data.token');

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Token revoked.');

        // The test client reuses one application instance, so the auth guard
        // caches the user resolved during /logout; a real PHP-FPM process
        // starts fresh on every request. forgetGuards() emulates the fresh
        // process so the revoked token is looked up against the database.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }
}
