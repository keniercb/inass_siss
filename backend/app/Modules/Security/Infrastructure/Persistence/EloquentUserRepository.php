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

    public function findById(int $id): ?User
    {
        return User::query()->find($id);
    }

    public function findOwnerOfPerson(int $personId): ?User
    {
        // withTrashed mirrors the database constraint: a deactivated
        // account still reserves its person (users.person_id UNIQUE
        // covers soft-deleted rows).
        return User::withTrashed()
            ->where('person_id', $personId)
            ->first();
    }

    public function linkPerson(User $user, int $personId): User
    {
        $user->forceFill(['person_id' => $personId])->save();

        return $user->refresh();
    }

    public function unlinkPerson(User $user): User
    {
        $user->forceFill(['person_id' => null])->save();

        return $user->refresh();
    }
}
