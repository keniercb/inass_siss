<?php

declare(strict_types=1);

namespace App\Modules\People\Infrastructure\Persistence;

use App\Modules\People\Application\Contracts\PeopleRepositoryInterface;
use App\Modules\People\Domain\DuplicateCandidate;
use App\Modules\People\Infrastructure\Persistence\Models\Person;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Eloquent persistence for people (ADR-11): the single data-access
 * point of the People module. Search implements the RF-PER-004
 * surface — exact identity, multi-word name fragments crossing the
 * four name columns, sex, deceased state and birth-date range — with
 * the listing order of idx_people_names so homonyms cluster together
 * and disambiguation is visually immediate. Identity and citizen
 * card lookups include soft-deleted people because RN-001 reserves
 * the identity and RF-PER-005 the card for the whole registry, while
 * homonym candidates only consider living people.
 */
final class EloquentPeopleRepository implements PeopleRepositoryInterface
{
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = Person::query()
            ->orderBy('first_surname')
            ->orderBy('first_name')
            ->orderBy('birth_date');

        if (isset($filters['identity']) && $filters['identity'] !== '') {
            $query->where('identity_number', $filters['identity']);
        }

        if (isset($filters['q']) && $filters['q'] !== '') {
            // "Combinación de nombres/apellidos" (RF-PER-004): every
            // word of the query must appear somewhere in the person's
            // full name, so "Juan Pérez" matches both "Juan Pérez" and
            // "Juan Carlos Pérez Gómez" while narrowing like an AND.
            $words = preg_split('/\s+/u', trim((string) $filters['q'])) ?: [];

            foreach ($words as $word) {
                $query->whereRaw(
                    "LOWER(CONCAT_WS(' ', first_name, middle_name, first_surname, second_surname)) LIKE ?",
                    ['%'.mb_strtolower($word).'%'],
                );
            }
        }

        if (isset($filters['sex']) && $filters['sex'] !== '') {
            $query->where('sex', $filters['sex']);
        }

        if (array_key_exists('deceased', $filters) && $filters['deceased'] !== null) {
            $filters['deceased']
                ? $query->whereNotNull('death_date')
                : $query->whereNull('death_date');
        }

        if (isset($filters['birth_from']) && $filters['birth_from'] !== '') {
            $query->where('birth_date', '>=', $filters['birth_from']);
        }

        if (isset($filters['birth_to']) && $filters['birth_to'] !== '') {
            $query->where('birth_date', '<=', $filters['birth_to']);
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return $paginator;
    }

    public function find(int $id): ?Person
    {
        return Person::query()->find($id);
    }

    public function findByIdIncludingDeactivated(int $id): ?Person
    {
        return Person::withTrashed()->find($id);
    }

    public function findByIdentityNumber(string $identityNumber): ?Person
    {
        // withTrashed on purpose (RN-001): the identity of a
        // deactivated person cannot be reused.
        return Person::withTrashed()
            ->where('identity_number', $identityNumber)
            ->first();
    }

    public function citizenCardExists(string $citizenCardId): bool
    {
        return Person::withTrashed()
            ->where('citizen_card_id', $citizenCardId)
            ->exists();
    }

    public function homonymCandidates(string $firstName, string $firstSurname, string $birthDate): array
    {
        return Person::query()
            ->where('first_name', $firstName)
            ->where('first_surname', $firstSurname)
            ->where('birth_date', $birthDate)
            ->whereNull('death_date')
            ->orderBy('id')
            ->get()
            ->map(fn (Person $person): DuplicateCandidate => new DuplicateCandidate(
                $person->id,
                $person->identity_number,
                $person->first_name,
                $person->first_surname,
                $person->birth_date->format('Y-m-d'),
            ))
            ->all();
    }

    public function create(array $attributes): Person
    {
        $person = new Person;
        $person->fill($attributes);
        $person->save();

        return $person->refresh();
    }

    public function update(Person $person, array $attributes): Person
    {
        $person->fill($attributes);
        $person->save();

        return $person->refresh();
    }

    public function registerDeath(Person $person, string $deathDate): Person
    {
        $person->death_date = $deathDate;
        $person->save();

        return $person->refresh();
    }

    public function softDelete(Person $person): bool
    {
        return (bool) $person->delete();
    }
}
