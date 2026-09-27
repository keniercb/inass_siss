<?php

declare(strict_types=1);

namespace App\Modules\People\Application\Contracts;

use App\Modules\People\Application\Exceptions\HomonymCandidatesException;
use App\Modules\People\Application\Exceptions\IdentityAlreadyRegisteredException;
use App\Modules\People\Infrastructure\Persistence\Models\Person;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Use cases of the People module (RF-PER-001..005).
 */
interface PeopleServiceInterface
{
    /**
     * Register a person (RF-PER-001) after the duplicate policy
     * (RF-PER-005): an already-registered identity throws with the
     * registered person; living homonyms throw unless confirmed.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws IdentityAlreadyRegisteredException 409 with the registered person
     * @throws HomonymCandidatesException 409 with the candidates
     */
    public function create(array $attributes): Person;

    /**
     * Edit a person (RF-PER-002): the identity number is immutable
     * and the death date belongs to the death endpoint.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException semantic 422 for the guarded fields
     */
    public function update(int $id, array $attributes): ?Person;

    /**
     * Register (or correct) the death date (RF-PER-003): strictly
     * after birth, never in the future ("now" from the Shared Clock).
     *
     * @throws ValidationException semantic 422 for the date guards
     */
    public function registerDeath(int $id, string $deathDate): ?Person;

    /**
     * Search (RF-PER-004).
     *
     * @param  array{identity?: string, q?: string, sex?: string, deceased?: bool, birth_from?: string, birth_to?: string}  $filters
     * @return LengthAwarePaginator<int, Person>
     */
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator;

    public function get(int $id): ?Person;

    /**
     * Whether the person may start a new process (S3.5, RF-SEG-003,
     * defense in depth): only living and active people qualify.
     * PensionCases (F3) enforces this at submission time consulting
     * this port, so the state rule stays owned by People.
     *
     * @throws \InvalidArgumentException when the person does not exist
     */
    public function canStartNewProcess(int $personId): bool;

    public function delete(int $id): bool;
}
