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
    version: '1.1.0',
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
#[OA\Tag(name: 'Auditoría', description: 'Bitácora append-only de acciones: consulta filtrable y exportación CSV (RF-AUD-001, RF-AUD-003)')]
#[OA\Tag(name: 'Personas', description: 'Registro único de personas: alta con control de duplicados, edición auditada, fallecimiento y búsqueda (RF-PER-001..005)')]
#[OA\Tag(name: 'Usuarios', description: 'Cuentas de usuario: vinculación con personas del registro único para la trazabilidad de acciones (RF-SEG-004)')]
#[OA\Tag(name: 'Roles', description: 'Gestión de roles y catálogo de permisos: los cinco institucionales inmutables de la sección 2.2, los personalizados con subconjuntos del catálogo y la superficie de solo lectura de permisos que consume el editor de roles (RF-SEG-002, ADR-26/27)')]
#[OA\Tag(name: 'Estructura', description: 'Estructura organizacional: entidades, oficinas, jerarquías acíclicas (RN-003), firmas autorizadas y árbol de consulta (RF-ENT-001..005)')]
#[OA\Tag(name: 'Base legal', description: 'Corpus legal: bases con terna tipo-número-año única, año derivado de la emisión (H-11), vigencias derivadas (RN-006) y consulta documental (RF-LEG-001..004)')]
#[OA\Tag(name: 'Expedientes', description: 'Expedientes de pensión: apertura con número secuencial (RN-009), subregistros de salarios/servicios/ciclos con validaciones RN-005 y advertencias de evidencia (RF-EXP-001..004)')]
final class ApiDoc {}
