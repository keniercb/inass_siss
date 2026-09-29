<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Resources;

use App\Modules\People\Infrastructure\Persistence\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Linked person summary (S3.5, RF-SEG-004): the projection /auth/me
 * and the user responses carry so consumers know the natural person
 * behind the account. Deliberately minimal: identity, display name
 * and the derived life state; anything richer belongs to the People
 * endpoints.
 *
 * @mixin Person
 */
#[OA\Schema(
    schema: 'LinkedPerson',
    title: 'Persona vinculada',
    description: 'Resumen de la persona del registro único vinculada a una cuenta (RF-SEG-004). deceased se deriva de death_date.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'identity_number', type: 'string', example: '85061510002', description: 'Carné de identidad de la persona vinculada (RN-001)'),
        new OA\Property(property: 'full_name', type: 'string', example: 'Juan Carlos Pérez Gómez', description: 'Nombre de visualización compuesto de los cuatro campos de nombre'),
        new OA\Property(property: 'deceased', type: 'boolean', example: false, description: 'Derivado: death_date no nula; una persona fallecida no puede iniciar trámites nuevos (RF-SEG-003)'),
    ],
)]
final class LinkedPersonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'identity_number' => $this->identity_number,
            'full_name' => trim(sprintf(
                '%s %s %s %s',
                $this->first_name ?? '',
                $this->middle_name ?? '',
                $this->first_surname ?? '',
                $this->second_surname ?? '',
            )),
            'deceased' => $this->death_date !== null,
        ];
    }
}
