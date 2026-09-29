<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\People\Infrastructure\Persistence\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Person factory for the People module suites (RF-PER-*).
 *
 * The generated identity numbers are structurally valid under
 * RN-001 as corrected (11 digits; month 01-12 and day 01-31 taken
 * from the birth date, which stays coherent; sex parity in digit
 * 10 — even male, odd female), so every created person passes the
 * CubanIdentityNumber rule. The registry sequence is a per-process
 * counter: collisions inside one test would need 900 people, and
 * RefreshDatabase resets the table between tests, so cross-test
 * repetition is harmless.
 *
 * @extends Factory<Person>
 */
final class PersonFactory extends Factory
{
    private static int $sequence = 0;

    /**
     * The model the factory belongs to. Explicit because the model
     * lives inside the People module, not App\Models, so name-based
     * discovery does not apply (ADR-11).
     *
     * @var class-string<Person>
     */
    protected $model = Person::class;

    public function definition(): array
    {
        return [
            'identity_number' => self::identity('M', '1985-06-15'),
            'first_name' => 'Juan',
            'middle_name' => 'Carlos',
            'first_surname' => 'Pérez',
            'second_surname' => 'Gómez',
            'sex' => 'M',
            'race_id' => null,
            'address' => 'Calle 23 #45 e/ 10 y 12, Vedado, La Habana',
            'birth_date' => '1985-06-15',
            'death_date' => null,
            'father_name' => 'Pedro Pérez Rodríguez',
            'mother_name' => 'María Gómez Fernández',
            'citizen_card_id' => null,
        ];
    }

    /** A structurally valid identity number for the given sex and birth date. */
    public static function identity(string $sex, string $birthDate): string
    {
        $sequence = (self::$sequence++ % 900) + 100;

        [$year, $month, $day] = explode('-', $birthDate);

        // Digit 10 encodes the sex: even male, odd female (RN-001).
        $sexDigit = $sex === 'F' ? 1 : 0;

        return sprintf('%s%s%s%03d%d6', substr($year, 2, 2), $month, $day, $sequence, $sexDigit);
    }

    /** Sets the birth date (and a coherent identity number) for a male person. */
    public function born(string $birthDate): static
    {
        return $this->state(fn (): array => [
            'birth_date' => $birthDate,
            'identity_number' => self::identity('M', $birthDate),
        ]);
    }

    /** Female person with a coherent identity number. */
    public function female(): static
    {
        return $this->state(fn (): array => [
            'sex' => 'F',
            'identity_number' => self::identity('F', $this->definition()['birth_date']),
        ]);
    }

    /** A person with the minimal required fields only. */
    public function minimal(): static
    {
        return $this->state(fn (): array => [
            'middle_name' => null,
            'second_surname' => null,
            'race_id' => null,
            'father_name' => null,
            'mother_name' => null,
            'citizen_card_id' => null,
        ]);
    }

    public function deceased(string $deathDate): static
    {
        return $this->state(fn (): array => [
            'death_date' => $deathDate,
        ]);
    }
}
