<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Presentation\Resources;

use App\Modules\Organizations\Domain\SignatureStatus;
use App\Modules\Organizations\Infrastructure\Persistence\Models\AuthorizedSignature;
use App\Modules\Shared\Contracts\ClockInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Authorized signature projection (RF-ENT-003): the tern with its
 * nested references, the optional validity window and the status
 * DERIVED at read time from the window against the Shared Clock —
 * the same convention as the derived deceased flag of People. The
 * clock resolves through the container here because JsonResource
 * instances are framework-built; the port stays the single time
 * source (tests may swap the binding to freeze time).
 *
 * @mixin AuthorizedSignature
 */
#[OA\Schema(
    schema: 'AuthorizedSignature',
    title: 'Firma autorizada',
    description: 'Firma autorizada de una entidad (RF-ENT-003). La terna entidad+persona+cargo es única y queda reservada por el historial de revocación; status se deriva de la ventana de vigencia al leer, nunca se almacena.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 9),
        new OA\Property(
            property: 'entity',
            type: 'object',
            description: 'Entidad que autoriza',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
                new OA\Property(property: 'code', type: 'string', example: 'ENT-0001'),
                new OA\Property(property: 'tax_id_number', type: 'string', example: '11000012345'),
            ],
        ),
        new OA\Property(
            property: 'person',
            type: 'object',
            description: 'Persona autorizada (resumen del registro único)',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 5),
                new OA\Property(property: 'identity_number', type: 'string', example: '18506150012'),
                new OA\Property(property: 'full_name', type: 'string', example: 'Juan Carlos Pérez Gómez'),
            ],
        ),
        new OA\Property(
            property: 'position',
            type: 'object',
            description: 'Cargo en que firma',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 2),
                new OA\Property(property: 'name', type: 'string', example: 'Director General'),
            ],
        ),
        new OA\Property(property: 'valid_from', type: 'string', format: 'date', nullable: true, example: '2020-01-01', description: 'Inicio de vigencia opcional; null = vigente desde siempre'),
        new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true, example: '2030-12-31', description: 'Fin de vigencia opcional (≥ valid_from, RN-006); null = hasta nueva orden'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'future', 'expired'], example: 'active', description: 'Derivado de la ventana al momento de la lectura'),
    ],
)]
final class AuthorizedSignatureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity' => $this->whenLoaded('entity', fn () => $this->entity === null ? null : [
                'id' => $this->entity->id,
                'code' => $this->entity->code,
                'tax_id_number' => $this->entity->tax_id_number,
            ]),
            'person' => $this->whenLoaded('person', fn () => $this->person === null ? null : [
                'id' => $this->person->id,
                'identity_number' => $this->person->identity_number,
                'full_name' => trim(sprintf(
                    '%s %s %s %s',
                    (string) $this->person->first_name,
                    (string) ($this->person->middle_name ?? ''),
                    (string) $this->person->first_surname,
                    (string) ($this->person->second_surname ?? ''),
                )),
            ]),
            'position' => $this->whenLoaded('position', fn () => $this->position === null ? null : [
                'id' => $this->position->id,
                'name' => $this->position->name,
            ]),
            'valid_from' => $this->valid_from?->format('Y-m-d'),
            'valid_to' => $this->valid_to?->format('Y-m-d'),
            'status' => SignatureStatus::resolve(
                $this->valid_from?->format('Y-m-d'),
                $this->valid_to?->format('Y-m-d'),
                app(ClockInterface::class),
            )->value,
        ];
    }
}
