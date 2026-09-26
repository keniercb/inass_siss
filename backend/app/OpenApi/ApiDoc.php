<?php

declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Global SGP API metadata (RF-API-001, RF-API-002).
 *
 * Single source of truth for the OpenAPI info block and the Sanctum
 * security scheme. Endpoints and schemas are documented with attributes
 * in each module's Presentation layer, so the spec grows with the code
 * and never drifts from the routes it describes (ADR-13).
 */
#[OA\Info(
    version: '1.0.0',
    title: 'SGP API',
    description: 'API del Sistema de Gestión de Pensionados (SGP) del Ministerio de Trabajo. Todos los endpoints viven bajo el prefijo /api/v1. Envelope de respuestas conforme RF-API-002: campo `data` en éxito, `message` para respuestas de un solo mensaje y 422 con errores por campo.',
    contact: new OA\Contact(name: 'Equipo Backend SGP'),
)]
#[OA\SecurityScheme(
    securityScheme: 'sanctumAuth',
    type: 'http',
    scheme: 'bearer',
    description: 'Token Bearer personal emitido por POST /api/v1/auth/login (Sanctum).',
)]
#[OA\Tag(name: 'Auth', description: 'Autenticación y sesión (RF-SEG-001)')]
#[OA\Tag(name: 'Catalogs', description: 'Catálogos uniformes, municipios y agencias (RF-CAT-001..006)')]
#[OA\Tag(name: 'Settings', description: 'Configuración general versionada y futuras secuencias de numeración (RF-CAT-005, RN-007)')]
final class ApiDoc {}
