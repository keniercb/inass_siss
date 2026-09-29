<?php

declare(strict_types=1);

namespace App\Modules\People\Presentation\Resources;

use App\Modules\People\Infrastructure\Persistence\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Person projection (RF-PER-001..004). It carries the fields the
 * search needs to disambiguate homonyms (identity, birth date and
 * parents) plus the derived deceased flag: death is a date, "being
 * deceased" is derived state, never a column (model data 5.4).
 *
 * @mixin Person
 */
#[OA\Schema(
    schema: 'Person',
    title: 'Persona',
    description: 'Persona del registro único del SGP (RF-PER-001). deceased se deriva de death_date: el fallecimiento es una fecha, estar fallecido es estado derivado, nunca una columna.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'identity_number', type: 'string', example: '85061510002', description: 'Carné de identidad: 11 dígitos (mes 01-12 y día 01-31 validados, sexo por paridad del dígito 10), único e inmutable (RN-001)'),
        new OA\Property(property: 'first_name', type: 'string', example: 'Juan', description: 'Primer nombre'),
        new OA\Property(property: 'middle_name', type: 'string', nullable: true, example: 'Carlos', description: 'Segundo nombre'),
        new OA\Property(property: 'first_surname', type: 'string', example: 'Pérez', description: 'Primer apellido'),
        new OA\Property(property: 'second_surname', type: 'string', nullable: true, example: 'Gómez', description: 'Segundo apellido'),
        new OA\Property(property: 'sex', type: 'string', enum: ['M', 'F'], example: 'M', description: 'Sexo (CHECK de BD)'),
        new OA\Property(property: 'race_id', type: 'integer', format: 'int64', nullable: true, example: 3, description: 'Raza declarada (catálogo)'),
        new OA\Property(property: 'address', type: 'string', example: 'Calle 23 #45, Vedado, La Habana'),
        new OA\Property(property: 'birth_date', type: 'string', format: 'date', example: '1985-06-15'),
        new OA\Property(property: 'death_date', type: 'string', format: 'date', nullable: true, example: null, description: 'Fecha de fallecimiento (RF-PER-003); null mientras viva'),
        new OA\Property(property: 'father_name', type: 'string', nullable: true, example: 'Pedro Pérez Rodríguez', description: 'Desambiguación de homónimos (RF-PER-004)'),
        new OA\Property(property: 'mother_name', type: 'string', nullable: true, example: 'María Gómez Fernández', description: 'Desambiguación de homónimos (RF-PER-004)'),
        new OA\Property(property: 'citizen_card_id', type: 'string', nullable: true, example: null, description: 'Ficha única de ciudadano, opcional y única (RF-PER-005)'),
        new OA\Property(property: 'deceased', type: 'boolean', example: false, description: 'Derivado: death_date no nula'),
    ],
)]
final class PersonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'identity_number' => $this->identity_number,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'first_surname' => $this->first_surname,
            'second_surname' => $this->second_surname,
            'sex' => $this->sex,
            'race_id' => $this->race_id,
            'address' => $this->address,
            'birth_date' => $this->birth_date->format('Y-m-d'),
            'death_date' => $this->death_date?->format('Y-m-d'),
            'father_name' => $this->father_name,
            'mother_name' => $this->mother_name,
            'citizen_card_id' => $this->citizen_card_id,
            'deceased' => $this->death_date !== null,
        ];
    }
}
