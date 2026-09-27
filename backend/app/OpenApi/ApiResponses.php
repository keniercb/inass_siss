<?php

declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Reusable OpenAPI response components (ADR-13).
 *
 * Centralizes the cross-cutting error shapes of the SGP API so every
 * protected operation references the same component instead of
 * duplicating inline definitions: 401 for the Sanctum bearer scheme,
 * 403 for the RBAC permission guard (RF-SEG-002, ADR-18) and 422 for
 * the per-field validation envelope (RF-API-002).
 */
#[OA\Response(
    response: 'Unauthorized',
    description: 'Token ausente, inválido o revocado',
    content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.'),
        ],
    ),
)]
#[OA\Response(
    response: 'Forbidden',
    description: 'Sin el permiso requerido para la operación (RF-SEG-002)',
    content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'message', type: 'string', example: 'Forbidden.'),
        ],
    ),
)]
#[OA\Response(
    response: 'ValidationError',
    description: 'Payload inválido o regla de negocio incumplida (errores por campo)',
    content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'message', type: 'string', example: 'The code is already in use.'),
            new OA\Property(
                property: 'errors',
                type: 'object',
                properties: [
                    new OA\Property(
                        property: 'code',
                        type: 'array',
                        items: new OA\Items(type: 'string', example: 'The code is already in use.'),
                    ),
                ],
            ),
        ],
    ),
)]
final class ApiResponses {}
