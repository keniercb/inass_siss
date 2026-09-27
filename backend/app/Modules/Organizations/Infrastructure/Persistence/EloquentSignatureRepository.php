<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Infrastructure\Persistence;

use App\Modules\Organizations\Application\Contracts\SignatureRepositoryInterface;
use App\Modules\Organizations\Domain\SignatureStatus;
use App\Modules\Organizations\Infrastructure\Persistence\Models\AuthorizedSignature;
use App\Modules\Shared\Contracts\ClockInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Eloquent persistence for authorized signatures (ADR-11). The
 * derived-status filter resolves the validity windows against the
 * Shared Clock (Infrastructure may resolve time only through the
 * port, same discipline as Application), and the tern probe includes
 * revoked rows because the UNIQUE index covers the whole history.
 */
final class EloquentSignatureRepository implements SignatureRepositoryInterface
{
    /** Relations every read projection needs (single source). */
    private const WITH = ['entity.organization', 'person', 'position'];

    public function __construct(
        private readonly ClockInterface $clock,
    ) {}

    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = AuthorizedSignature::query()
            ->with(self::WITH)
            ->orderBy('id');

        foreach (['entity_id', 'person_id', 'position_id'] as $column) {
            if (isset($filters[$column]) && $filters[$column] !== null) {
                $query->where($column, (int) $filters[$column]);
            }
        }

        if (isset($filters['status']) && $filters['status'] instanceof SignatureStatus) {
            $today = $this->clock->now()->format('Y-m-d');

            match ($filters['status']) {
                SignatureStatus::Active => $query->where(function ($group) use ($today): void {
                    $group->where(function ($window) use ($today): void {
                        $window->whereNull('valid_from')->orWhere('valid_from', '<=', $today);
                    })->where(function ($window) use ($today): void {
                        $window->whereNull('valid_to')->orWhere('valid_to', '>=', $today);
                    });
                }),
                SignatureStatus::Future => $query->where('valid_from', '>', $today),
                SignatureStatus::Expired => $query->where('valid_to', '<', $today),
            };
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return $paginator;
    }

    public function find(int $id): ?AuthorizedSignature
    {
        return AuthorizedSignature::query()
            ->with(self::WITH)
            ->find($id);
    }

    public function ternExists(int $entityId, int $personId, int $positionId, ?int $exceptId = null): bool
    {
        // withTrashed on purpose (RF-ENT-003): a revoked signature
        // keeps the tern reserved; history cannot be overwritten.
        return AuthorizedSignature::withTrashed()
            ->where('entity_id', $entityId)
            ->where('person_id', $personId)
            ->where('position_id', $positionId)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();
    }

    public function create(array $attributes): AuthorizedSignature
    {
        $signature = new AuthorizedSignature;
        $signature->fill($attributes);
        $signature->save();

        return $signature->refresh()->load(self::WITH);
    }

    public function update(AuthorizedSignature $signature, array $attributes): AuthorizedSignature
    {
        $signature->fill($attributes);
        $signature->save();

        return $signature->refresh()->load(self::WITH);
    }

    public function softDelete(AuthorizedSignature $signature): bool
    {
        return (bool) $signature->delete();
    }
}
