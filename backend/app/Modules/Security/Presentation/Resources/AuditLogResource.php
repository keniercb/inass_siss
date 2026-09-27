<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Resources;

use App\Modules\Security\Application\DTO\AuditLogEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** @mixin AuditLogEntry */
#[OA\Schema(
    schema: 'AuditLogEntry',
    title: 'Entrada de bitácora',
    description: 'Registro append-only de una escritura crítica: autor, sujeto, valores previos y nuevos, y contexto de la solicitud (RF-AUD-001).',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 42),
        new OA\Property(property: 'event', type: 'string', enum: ['created', 'updated', 'deleted', 'restored'], example: 'updated'),
        new OA\Property(property: 'description', type: 'string', example: 'updated'),
        new OA\Property(property: 'causer_id', type: 'integer', format: 'int64', example: 1, nullable: true),
        new OA\Property(property: 'subject_type', type: 'string', example: 'App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\Race', nullable: true),
        new OA\Property(property: 'subject_id', type: 'integer', format: 'int64', example: 3, nullable: true),
        new OA\Property(
            property: 'old',
            type: 'object',
            description: 'Valores previos de los atributos modificados (nulo en created/restored)',
            nullable: true,
            example: '{"name": "Otra"}',
        ),
        new OA\Property(
            property: 'changes',
            type: 'object',
            description: 'Valores nuevos de los atributos modificados (nulo en deleted)',
            nullable: true,
            example: '{"name": "Otra raza"}',
        ),
        new OA\Property(property: 'request_id', type: 'string', example: '9d5b1c2e-6f4a-4c1e-8f3b-2a7d9e0c5b41', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-09-27T12:00:00.000000Z'),
    ],
)]
final class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'description' => $this->description,
            'causer_id' => $this->causerId,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'old' => $this->old,
            'changes' => $this->changes,
            'request_id' => $this->requestId,
            'created_at' => $this->occurredAt()->toIso8601String(),
        ];
    }
}
