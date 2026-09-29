<?php

declare(strict_types=1);

namespace App\Modules\Security\Infrastructure\Persistence;

use App\Modules\Organizations\Infrastructure\Persistence\Models\Office;
use App\Modules\Security\Application\Contracts\UserRepositoryInterface;
use App\Modules\Security\Domain\Authentication\LockoutState;
use App\Modules\Security\Infrastructure\Persistence\Models\Role;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use App\Modules\Shared\Support\AuditRecorder;
use DateTimeImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Eloquent + Sanctum implementation of the user repository port.
 *
 * The single place in the Security module allowed to touch the
 * users, personal_access_tokens and (read-only, for the role
 * catalog probe) roles tables (architecture doc section 6: Eloquent
 * is confined to Infrastructure; ADR-11).
 *
 * Audit semantics (ADR-19/ADR-24): business writes go through the
 * normal save pipeline so the observers watching User land them in
 * the bitácora (password redacted); login bookkeeping is QUIET
 * (saveQuietly) so a flood of failed attempts never pollutes the
 * trail; and role pivots — invisible to Eloquent events — get an
 * explicit AuditRecorder entry with the previous assignment.
 */
final class EloquentUserRepository implements UserRepositoryInterface
{
    public function __construct(
        private readonly AuditRecorder $audit,
    ) {}

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

    public function findByIdIncludingDeactivated(int $id): ?User
    {
        return User::withTrashed()->find($id);
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

    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        // Office relations travel with the directory rows (ADR-29):
        // UserResource renders the territorial scope of each account.
        $query = User::query()
            ->with(['office.officeType', 'office.province', 'office.municipality'])
            ->orderBy('email');

        if (isset($filters['q']) && $filters['q'] !== '') {
            // Free text over the account's two textual fields; words
            // narrow like an AND (same contract as the people search).
            $words = preg_split('/\s+/u', trim((string) $filters['q'])) ?: [];

            foreach ($words as $word) {
                $needle = mb_strtolower($word);

                $query->where(function ($builder) use ($needle): void {
                    $builder->whereRaw('LOWER(name) LIKE ?', ['%'.$needle.'%'])
                        ->orWhereRaw('LOWER(email) LIKE ?', ['%'.$needle.'%']);
                });
            }
        }

        if (isset($filters['role']) && $filters['role'] !== '') {
            $query->whereHas('roles', fn ($builder) => $builder->where('name', $filters['role']));
        }

        $status = $filters['status'] ?? 'active';

        if ($status === 'inactive') {
            $query->onlyTrashed();
        } elseif ($status === 'all') {
            $query->withTrashed();
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return $paginator;
    }

    public function emailTaken(string $email): bool
    {
        // Reservation probe: the users.email UNIQUE spans soft-deleted
        // rows, so a deactivated account keeps blocking its address.
        return User::withTrashed()->where('email', $email)->exists();
    }

    public function officeIsActive(int $officeId): bool
    {
        // Active-only probe (ADR-29): the Office model soft-deletes, so
        // the default scope answers "present in the active map".
        return Office::query()->whereKey($officeId)->exists();
    }

    public function createUser(
        string $name,
        string $email,
        string $password,
        array $roles,
        DateTimeImmutable $passwordChangedAt,
        ?int $officeId = null,
    ): User {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            // The hashed cast takes care of the secret at assignment.
            'password' => $password,
            'password_changed_at' => $passwordChangedAt,
            'office_id' => $officeId,
        ]);

        $this->syncRolesAndAudit($user, $roles, initial: true);

        return $user->refresh();
    }

    public function updateUser(
        User $user,
        ?string $name,
        ?array $roles,
        ?int $officeId = null,
        bool $officeIdPresent = false,
    ): User {
        if ($name !== null && $name !== $user->name) {
            $user->name = $name;
            $user->save();
        }

        // PATCH semantics (ADR-29): only a PRESENT key writes — an
        // absent office_id leaves the current scope untouched, an
        // explicit null clears it. The save pipeline keeps the office
        // change inside the audited attribute diff.
        if ($officeIdPresent && $officeId !== $user->office_id) {
            $user->office_id = $officeId;
            $user->save();
        }

        if ($roles !== null) {
            $this->syncRolesAndAudit($user, $roles, initial: false);
        }

        return $user->refresh();
    }

    public function deactivate(User $user): void
    {
        // Sessions die with the account: a deactivated row keeps its
        // email/person reservation but can no longer authenticate —
        // neither fresh logins (401) nor tokens issued earlier.
        $user->tokens()->delete();
        $user->delete();
    }

    public function restore(User $user): User
    {
        $user->restore();

        return $user->refresh();
    }

    public function unlock(User $user): User
    {
        // Normal save: the Administrator's unlock lands in the trail
        // with the previous lock state (old.failed_login_attempts,
        // old.locked_at).
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_at' => null,
        ])->save();

        return $user->refresh();
    }

    public function recordFailedAttempt(User $user, LockoutState $state): void
    {
        // Silent by design (ADR-24): brute-force bookkeeping is not a
        // business change and must not flood the append-only trail.
        $user->forceFill([
            'failed_login_attempts' => $state->failedAttempts,
            'locked_at' => $state->lockedAt,
        ])->saveQuietly();
    }

    public function clearLoginFailures(User $user): void
    {
        if ((int) $user->failed_login_attempts === 0 && $user->locked_at === null) {
            return;
        }

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_at' => null,
        ])->saveQuietly();
    }

    public function resetPassword(User $user, string $password, DateTimeImmutable $changedAt): User
    {
        // One audited write: secret (redacted in the trail), fresh
        // baseline and a clean lockout state.
        $user->forceFill([
            'password' => $password,
            'password_changed_at' => $changedAt,
            'failed_login_attempts' => 0,
            'locked_at' => null,
        ])->save();

        return $user->refresh();
    }

    public function revokeAllTokens(User $user): void
    {
        $user->tokens()->delete();
    }

    public function revokeOtherTokens(User $user): void
    {
        $current = $user->currentAccessToken();

        $query = $user->tokens();

        if ($current !== null) {
            $query->whereKeyNot($current->id);
        }

        $query->delete();
    }

    public function roleNamesOf(User $user): array
    {
        return $user->getRoleNames()->values()->all();
    }

    public function unknownRoles(array $roles): array
    {
        if ($roles === []) {
            return [];
        }

        $existing = Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $roles)
            ->pluck('name')
            ->all();

        return array_values(array_diff($roles, $existing));
    }

    public function countActiveAdministrators(User $except): int
    {
        return User::query()
            ->whereKeyNot($except->id)
            ->role('admin')
            ->count();
    }

    /**
     * Syncs the spatie role assignment and records an explicit trail
     * entry with the PREVIOUS assignment: the pivot write is invisible
     * to Eloquent model events, so without this entry a role change
     * — a critical security edit — would escape the bitácora.
     *
     * @param  list<string>  $roles
     */
    private function syncRolesAndAudit(User $user, array $roles, bool $initial): void
    {
        $previous = $user->getRoleNames()->sort()->values()->all();

        $user->syncRoles($roles);

        $assigned = $user->getRoleNames()->sort()->values()->all();

        if ($initial && $previous === []) {
            // Creation: the created event already carries the row; the
            // roles entry documents the initial assignment on its own.
            $this->audit->record($user, 'updated', 'updated', [], ['roles' => $assigned]);

            return;
        }

        if ($assigned !== $previous) {
            $this->audit->record($user, 'updated', 'updated', ['roles' => $previous], ['roles' => $assigned]);
        }
    }
}
