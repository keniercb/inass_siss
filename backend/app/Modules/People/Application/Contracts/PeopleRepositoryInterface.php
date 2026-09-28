<?php

declare(strict_types=1);

namespace App\Modules\People\Application\Contracts;

use App\Modules\People\Domain\DuplicateCandidate;
use App\Modules\People\Infrastructure\Persistence\Models\Person;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Persistence port of the People module (ADR-11). Presentation and
 * Application code type-hint this contract; only the Infrastructure
 * layer knows about Eloquent.
 */
interface PeopleRepositoryInterface
{
    /**
     * Search with the RF-PER-004 surface: identity by prefix
     * (patrón ci_buscado%, 1-11 digits), name fragments
     * (multi-word, across the name columns), sex, deceased
     * state and birth date range, paginated and ordered by
     * first_surname / first_name / birth_date (idx_people_names).
     *
     * @param  array{identity?: string, q?: string, sex?: string, deceased?: bool, birth_from?: string, birth_to?: string}  $filters
     * @return LengthAwarePaginator<int, Person>
     */
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator;

    public function find(int $id): ?Person;

    /**
     * Id lookup including soft-deleted people (S3.5, RF-SEG-003):
     * the eligibility probe must tell deactivated people apart from
     * nonexistent ones — both fail, but only the latter is a caller
     * error.
     */
    public function findByIdIncludingDeactivated(int $id): ?Person;

    /**
     * Identity lookup including soft-deleted people (RN-001: the
     * identity of a deactivated person cannot be reused).
     */
    public function findByIdentityNumber(string $identityNumber): ?Person;

    /**
     * Citizen-card probe including soft-deleted people: the optional
     * card is unique in the whole registry (RF-PER-005), so the
     * service answers a semantic 422 instead of a driver error
     * (RN-008 convention; the UNIQUE index is the last defense).
     */
    public function citizenCardExists(string $citizenCardId): bool;

    /**
     * Living homonym candidates: same first name, first surname and
     * birth date (RF-PER-005). Deceased homonyms are history, not a
     * registry conflict.
     *
     * @return array<int, DuplicateCandidate>
     */
    public function homonymCandidates(string $firstName, string $firstSurname, string $birthDate): array;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Person;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Person $person, array $attributes): Person;

    public function registerDeath(Person $person, string $deathDate): Person;

    public function softDelete(Person $person): bool;
}
