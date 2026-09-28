<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application\Services;

use App\Modules\Catalogs\Application\Contracts\CatalogRepositoryInterface;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\CatalogModel;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Position;
use App\Modules\Organizations\Application\Contracts\EntityRepositoryInterface;
use App\Modules\Organizations\Application\Contracts\SignatureRepositoryInterface;
use App\Modules\Organizations\Application\Contracts\SignatureServiceInterface;
use App\Modules\Organizations\Domain\SignatureStatus;
use App\Modules\Organizations\Infrastructure\Persistence\Models\AuthorizedSignature;
use App\Modules\People\Application\Contracts\PeopleRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Use cases for authorized signatures (RF-ENT-003, S4.4).
 *
 * The entity+person+position tern is unique including revoked
 * history: the reservation answers a semantic 422 before touching the
 * UNIQUE index, and the revocation is a soft delete so the row stays
 * as the auditable record of who signed for which entity. The
 * validity window keeps RN-006 ordering; its status is derived at
 * read time (never stored) by the Domain SignatureStatus.
 */
final class SignatureService implements SignatureServiceInterface
{
    private const PAYLOAD_COLUMNS = ['entity_id', 'person_id', 'position_id', 'valid_from', 'valid_to'];

    /**
     * @param  CatalogRepositoryInterface<CatalogModel>  $catalogs
     */
    public function __construct(
        private readonly SignatureRepositoryInterface $signatures,
        private readonly EntityRepositoryInterface $entities,
        private readonly PeopleRepositoryInterface $people,
        private readonly CatalogRepositoryInterface $catalogs,
    ) {}

    public function create(array $attributes): AuthorizedSignature
    {
        $payload = $this->acceptedPayload($attributes);

        $this->assertMandatoryKeys($payload, ['entity_id', 'person_id', 'position_id']);
        $this->assertReferencesAreValid($payload);
        $this->assertTernIsFree($payload, null);
        $this->assertWindowIsCoherent($payload['valid_from'] ?? null, $payload['valid_to'] ?? null);

        return $this->signatures->create($payload);
    }

    public function update(int $id, array $attributes): ?AuthorizedSignature
    {
        $signature = $this->signatures->find($id);

        if ($signature === null) {
            return null;
        }

        $payload = $this->acceptedPayload($attributes);

        if ($payload === []) {
            return $signature;
        }

        // Only the validity window is editable: the tern identifies
        // the historical record (RF-ENT-003 versioning).
        $resulting = [
            'valid_from' => array_key_exists('valid_from', $payload)
                ? $payload['valid_from']
                : $signature->valid_from?->format('Y-m-d'),
            'valid_to' => array_key_exists('valid_to', $payload)
                ? $payload['valid_to']
                : $signature->valid_to?->format('Y-m-d'),
        ];

        $this->assertWindowIsCoherent($resulting['valid_from'], $resulting['valid_to']);

        return $this->signatures->update($signature, $payload);
    }

    /**
     * @param  array{entity_id?: int, person_id?: int, position_id?: int, status?: SignatureStatus}  $filters
     * @return LengthAwarePaginator<int, AuthorizedSignature>
     */
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->signatures->search($filters, $page, $perPage);
    }

    public function get(int $id): ?AuthorizedSignature
    {
        return $this->signatures->find($id);
    }

    public function delete(int $id): bool
    {
        $signature = $this->signatures->find($id);

        if ($signature === null) {
            return false;
        }

        return $this->signatures->softDelete($signature);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function acceptedPayload(array $attributes): array
    {
        $accepted = array_flip(self::PAYLOAD_COLUMNS);

        return array_intersect_key($attributes, $accepted);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function assertMandatoryKeys(array $payload, array $keys): void
    {
        $missing = [];
        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload) || $payload[$key] === null || $payload[$key] === '') {
                $missing[$key] = 'The field is required.';
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }

    /**
     * The three references must exist (entity active, person
     * registered including deactivated, position active).
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertReferencesAreValid(array $payload): void
    {
        $entityId = $payload['entity_id'] ?? null;

        if ($entityId === null || $this->entities->find((int) $entityId) === null) {
            throw ValidationException::withMessages([
                'entity_id' => 'The selected entity does not exist or is deactivated.',
            ]);
        }

        $personId = $payload['person_id'] ?? null;

        if ($personId === null || $this->people->findByIdIncludingDeactivated((int) $personId) === null) {
            throw ValidationException::withMessages([
                'person_id' => 'The selected person does not exist.',
            ]);
        }

        $positionId = $payload['position_id'] ?? null;

        if ($positionId === null || ! $this->catalogs->exists(Position::class, ['id' => $positionId])) {
            throw ValidationException::withMessages([
                'position_id' => 'The selected position does not exist.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertTernIsFree(array $payload, ?int $exceptId): void
    {
        $exists = $this->signatures->ternExists(
            (int) $payload['entity_id'],
            (int) $payload['person_id'],
            (int) $payload['position_id'],
            $exceptId,
        );

        if ($exists) {
            throw ValidationException::withMessages([
                'entity_id' => 'This person already holds a signature for the entity in that position (tern is unique, RF-ENT-003).',
            ]);
        }
    }

    /**
     * RN-006 ordering on the resulting window: the end never precedes
     * the start. Null boundaries mean an open window.
     */
    private function assertWindowIsCoherent(mixed $validFrom, mixed $validTo): void
    {
        if ($validFrom === null || $validFrom === '' || $validTo === null || $validTo === '') {
            return;
        }

        if (new \DateTimeImmutable((string) $validTo) < new \DateTimeImmutable((string) $validFrom)) {
            throw ValidationException::withMessages([
                'valid_to' => 'The validity end must not precede its start (RN-006).',
            ]);
        }
    }
}
