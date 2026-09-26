<?php

declare(strict_types=1);

namespace App\Modules\Security\Infrastructure\Persistence;

use App\Modules\Security\Application\Contracts\UserRepositoryInterface;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Eloquent + Sanctum implementation of the user repository port.
 *
 * The single place in the Security module allowed to touch the
 * users and personal_access_tokens tables (architecture doc
 * section 6: Eloquent is confined to Infrastructure; ADR-11).
 */
final class EloquentUserRepository implements UserRepositoryInterface
{
    public function findByEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }

    public function issueAccessToken(User $user): string
    {
        return $user->createToken('login')->plainTextToken;
    }

    public function revokeCurrentAccessToken(User $user): void
    {
        $token = $user->currentAccessToken();

        // The guard resolves bearer tokens to PersonalAccessToken models;
        // TransientToken (session auth) never reaches this API route.
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }
}
