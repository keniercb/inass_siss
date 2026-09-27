<?php

declare(strict_types=1);

namespace App\Modules\LegalBasis\Presentation\Resources;

use App\Modules\LegalBasis\Domain\LegalBasisStatus;
use App\Modules\LegalBasis\Infrastructure\Persistence\Models\LegalBasis;
use App\Modules\Shared\Contracts\ClockInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Legal basis projection (RF-LEG-002..004): the tern identity with
 * the derived year, the date window and the status DERIVED at read
 * time against the Shared Clock — the same convention as the derived
 * flags of People and signatures. The clock resolves through the
 * container here because JsonResource instances are
 * framework-built; the port stays the single time source (tests may
 * swap the binding to freeze time).
 *
 * @mixin LegalBasis
 */
#[OA\Schema(
    schema: 'LegalBasis',
    title: 'Base legal',
    description: 'Norma o resolución del corpus legal (RF-LEG-002). La terna tipo+número+año es única e inmutable; el año se deriva de la fecha de emisión (H-11); status se deriva de las fechas al leer, nunca se almacena.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 3),
        new OA\Property(
            property: 'type',
            type: 'object',
            description: 'Tipo de base legal (catálogo)',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
                new OA\Property(property: 'code', type: 'string', example: 'LEY'),
                new OA\Property(property: 'name', type: 'string', example: 'Ley'),
            ],
        ),
        new OA\Property(property: 'number', type: 'string', example: '128', description: 'Número del documento; con tipo y año forma la terna única'),
        new OA\Property(property: 'issue_date', type: 'string', format: 'date', example: '2019-07-16', description: 'Fecha de emisión; inmutable (de ella deriva el año)'),
        new OA\Property(property: 'effective_date', type: 'string', format: 'date', example: '2019-08-01', description: 'Puesta en vigor (≥ issue_date, RN-006)'),
        new OA\Property(property: 'derogation_date', type: 'string', format: 'date', nullable: true, example: null, description: 'Derogación (≥ effective_date, RN-006); null = vigente hasta nueva orden'),
        new OA\Property(
            property: 'organization',
            type: 'object',
            description: 'Organismo emisor (catálogo)',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
                new OA\Property(property: 'code', type: 'string', example: 'MTSS'),
                new OA\Property(property: 'name', type: 'string', example: 'Ministerio de Trabajo y Seguridad Social'),
            ],
        ),
        new OA\Property(property: 'year', type: 'integer', example: 2019, description: 'Derivado de issue_date (H-11); nunca viaja en el alta'),
        new OA\Property(property: 'reference', type: 'string', nullable: true, example: 'Gaceta Oficial Ordinaria No. 45 de 2019'),
        new OA\Property(property: 'status', type: 'string', enum: ['effective', 'derogated', 'future'], example: 'effective', description: 'Derivado de las fechas al momento de la lectura'),
    ],
)]
final class LegalBasisResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->whenLoaded('type', fn () => [
                'id' => $this->type?->id,
                'code' => $this->type?->code,
                'name' => $this->type?->name,
            ]),
            'number' => $this->number,
            'issue_date' => $this->issue_date->format('Y-m-d'),
            'effective_date' => $this->effective_date->format('Y-m-d'),
            'derogation_date' => $this->derogation_date?->format('Y-m-d'),
            'organization' => $this->whenLoaded('organization', fn () => [
                'id' => $this->organization?->id,
                'code' => $this->organization?->code,
                'name' => $this->organization?->name,
            ]),
            'year' => $this->year,
            'reference' => $this->reference,
            'status' => LegalBasisStatus::resolve(
                $this->effective_date->format('Y-m-d'),
                $this->derogation_date?->format('Y-m-d'),
                app(ClockInterface::class),
            )->value,
        ];
    }
}
