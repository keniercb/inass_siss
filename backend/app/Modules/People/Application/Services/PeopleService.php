<?php

declare(strict_types=1);

namespace App\Modules\People\Application\Services;

use App\Modules\People\Application\Contracts\PeopleRepositoryInterface;
use App\Modules\People\Application\Contracts\PeopleServiceInterface;
use App\Modules\People\Application\Exceptions\HomonymCandidatesException;
use App\Modules\People\Application\Exceptions\IdentityAlreadyRegisteredException;
use App\Modules\People\Domain\DuplicateCandidate;
use App\Modules\People\Domain\DuplicatePolicy;
use App\Modules\People\Domain\DuplicateVerdict;
use App\Modules\People\Infrastructure\Persistence\Models\Person;
use App\Modules\Shared\Contracts\ClockInterface;
use DateTimeImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Use cases for the People module (RF-PER-001..005).
 *
 * The service orchestrates the pure Domain duplicate policy with the
 * repository: an identity already in the registry (active or
 * deactivated, RN-001) answers 409 with the registered person, while
 * living homonyms warn the operator unless the request confirms the
 * registration. Edition keeps the identity immutable (RN-001) and
 * delegates the death date to the lifecycle endpoint, whose guards
 * (strictly after birth, never in the future) resolve "now" through
 * the Shared Clock port. Natural-key collisions are probed before
 * insert to answer a semantic 422 instead of a driver error (RN-008
 * convention) — the database constraints remain the last line of
 * defense.
 */
final class PeopleService implements PeopleServiceInterface
{
    public function __construct(
        private readonly PeopleRepositoryInterface $people,
        private readonly ClockInterface $clock,
        private readonly DuplicatePolicy $duplicatePolicy,
    ) {}

    public function create(array $attributes): Person
    {
        $identityNumber = (string) $attributes['identity_number'];
        $firstName = (string) $attributes['first_name'];
        $firstSurname = (string) $attributes['first_surname'];
        $birthDate = (string) $attributes['birth_date'];
        $confirmed = (bool) ($attributes['confirm'] ?? false);

        $registered = $this->people->findByIdentityNumber($identityNumber);

        $homonyms = [];

        if ($registered === null) {
            $homonyms = $this->people->homonymCandidates($firstName, $firstSurname, $birthDate);
        }

        $verdict = $this->duplicatePolicy->evaluate(
            $registered !== null ? $this->toCandidate($registered) : null,
            $homonyms,
            $confirmed,
        );

        if ($verdict === DuplicateVerdict::IdentityRegistered && $registered !== null) {
            throw new IdentityAlreadyRegisteredException($registered);
        }

        if ($verdict === DuplicateVerdict::HomonymWarning) {
            throw new HomonymCandidatesException($homonyms);
        }

        if (isset($attributes['citizen_card_id'])) {
            if ($this->people->citizenCardExists((string) $attributes['citizen_card_id'])) {
                throw ValidationException::withMessages([
                    'citizen_card_id' => 'The citizen card is already declared for another person.',
                ]);
            }
        }

        unset($attributes['confirm']);

        return $this->people->create($attributes);
    }

    public function update(int $id, array $attributes): ?Person
    {
        $person = $this->people->find($id);

        if ($person === null) {
            return null;
        }

        // RN-001: the identity number is immutable after creation and
        // the death date belongs to the audited lifecycle endpoint
        // (RF-PER-003). The request layer forbids both fields; this
        // guard protects programmatic callers of the port too.
        foreach (['identity_number', 'death_date'] as $guarded) {
            if (array_key_exists($guarded, $attributes)) {
                throw ValidationException::withMessages([
                    $guarded => "The {$guarded} field is not editable here.",
                ]);
            }
        }

        return $this->people->update($person, $attributes);
    }

    public function registerDeath(int $id, string $deathDate): ?Person
    {
        $person = $this->people->find($id);

        if ($person === null) {
            return null;
        }

        $death = new DateTimeImmutable($deathDate);

        if ($death <= $person->birth_date) {
            throw ValidationException::withMessages([
                'death_date' => 'The death date must be after the birth date.',
            ]);
        }

        if ($death > $this->clock->now()) {
            throw ValidationException::withMessages([
                'death_date' => 'The death date cannot be in the future.',
            ]);
        }

        return $this->people->registerDeath($person, $deathDate);
    }

    /**
     * @param  array{identity?: string, q?: string, sex?: string, deceased?: bool, birth_from?: string, birth_to?: string}  $filters
     * @return LengthAwarePaginator<int, Person>
     */
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->people->search($filters, $page, $perPage);
    }

    public function get(int $id): ?Person
    {
        return $this->people->find($id);
    }

    public function delete(int $id): bool
    {
        $person = $this->people->find($id);

        if ($person === null) {
            return false;
        }

        return $this->people->softDelete($person);
    }

    private function toCandidate(Person $person): DuplicateCandidate
    {
        return new DuplicateCandidate(
            $person->id,
            $person->identity_number,
            $person->first_name,
            $person->first_surname,
            $person->birth_date->format('Y-m-d'),
        );
    }
}
