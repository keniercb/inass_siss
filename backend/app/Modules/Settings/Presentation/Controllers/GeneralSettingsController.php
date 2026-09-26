<?php

declare(strict_types=1);

namespace App\Modules\Settings\Presentation\Controllers;

use App\Modules\Settings\Application\Contracts\GeneralSettingsServiceInterface;
use App\Modules\Settings\Application\Exceptions\VersionAlreadyEffectiveException;
use App\Modules\Settings\Presentation\Requests\CurrentGeneralSettingRequest;
use App\Modules\Settings\Presentation\Requests\GeneralSettingIndexRequest;
use App\Modules\Settings\Presentation\Requests\StoreGeneralSettingRequest;
use App\Modules\Settings\Presentation\Resources\GeneralSettingResource;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * HTTP surface for the versioned general settings (RF-CAT-005,
 * RN-007, ADR-16).
 *
 * Deliberately thin: validation arrives through the FormRequests, the
 * vigencia rules sit behind the GeneralSettingsServiceInterface port
 * and all data access lives in the repository behind it. There is no
 * update endpoint on purpose — versions are immutable and corrections
 * create new vigencias (RF-CAT-005) — and deletion only accepts
 * versions that have not taken effect yet (409 otherwise). The OA
 * attributes keep the OpenAPI spec attached to this surface (ADR-13).
 */
final class GeneralSettingsController
{
    public function __construct(
        private readonly GeneralSettingsServiceInterface $settings,
    ) {}

    #[OA\Get(
        path: '/api/v1/general-settings',
        operationId: 'generalSettingsIndex',
        tags: ['Settings'],
        summary: 'Listado paginado de las versiones de configuración',
        description: 'Lista las vigencias de la configuración general de la más reciente a la más antigua con su effective_to derivado (RF-CAT-005, RN-007).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\QueryParameter(name: 'page', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\QueryParameter(name: 'per_page', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Listado paginado con envelope RF-API-002',
                content: new OA\JsonContent(
                    required: ['data'],
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralSettingVersion')),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'total', type: 'integer', example: 3),
                                new OA\Property(property: 'last_page', type: 'integer', example: 1),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
        ],
    )]
    public function index(GeneralSettingIndexRequest $request): JsonResponse
    {
        $paginator = $this->settings->list(
            (int) ($request->validated('page') ?? 1),
            (int) ($request->validated('per_page') ?? 15),
        );

        return GeneralSettingResource::collection($paginator)->response();
    }

    #[OA\Post(
        path: '/api/v1/general-settings',
        operationId: 'generalSettingsStore',
        tags: ['Settings'],
        summary: 'Crear una nueva vigencia de la configuración',
        description: 'Crea una versión nueva e inmutable de los parámetros de cálculo con su fecha de entrada en vigor (RF-CAT-005). La fecha no puede coincidir con una vigencia existente (RN-007, sin solapamientos) y el por ciento máximo no puede ser menor que el base. No existe endpoint de edición: la corrección crea una versión nueva.',
        security: [['sanctumAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['min_work_years', 'min_age_men', 'min_age_women', 'base_calc_percent', 'max_calc_percent', 'annual_increase_percent', 'effective_from'],
                properties: [
                    new OA\Property(property: 'min_work_years', type: 'integer', minimum: 0, maximum: 100, example: 30),
                    new OA\Property(property: 'min_age_men', type: 'integer', minimum: 0, maximum: 120, example: 60),
                    new OA\Property(property: 'min_age_women', type: 'integer', minimum: 0, maximum: 120, example: 55),
                    new OA\Property(property: 'base_calc_percent', type: 'integer', minimum: 0, maximum: 100, example: 50),
                    new OA\Property(property: 'max_calc_percent', type: 'integer', minimum: 0, maximum: 100, example: 90),
                    new OA\Property(property: 'annual_increase_percent', type: 'integer', minimum: 0, maximum: 100, example: 1),
                    new OA\Property(property: 'effective_from', type: 'string', format: 'date', example: '2027-01-01'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Vigencia creada',
                content: new OA\JsonContent(required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralSettingVersion')]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function store(StoreGeneralSettingRequest $request): JsonResponse
    {
        $setting = $this->settings->create($request->validated());

        return response()->json(['data' => new GeneralSettingResource($setting)], 201);
    }

    #[OA\Get(
        path: '/api/v1/general-settings/current',
        operationId: 'generalSettingsCurrent',
        tags: ['Settings'],
        summary: 'Resolver la configuración vigente',
        description: 'Acción de dominio RN-007: devuelve la versión con la mayor effective_from menor o igual a la fecha (hoy por el puerto Clock, o la indicada en `at`). 404 cuando ninguna versión está aún en vigor. Los cálculos de la Fase 3 congelarán la versión resuelta.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\QueryParameter(name: 'at', description: 'Fecha de resolución opcional (Y-m-d)', schema: new OA\Schema(type: 'string', format: 'date', example: '2024-08-15')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Versión en vigor a la fecha',
                content: new OA\JsonContent(required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralSettingVersion')]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(
                response: 404,
                description: 'Ninguna versión en vigor a la fecha',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'No general settings are in effect at the given date.')]),
            ),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function current(CurrentGeneralSettingRequest $request): JsonResponse
    {
        $at = $request->validated('at');

        $setting = $this->settings->effectiveAt(
            $at === null ? null : new DateTimeImmutable((string) $at),
        );

        if ($setting === null) {
            abort(404, 'No general settings are in effect at the given date.');
        }

        return response()->json(['data' => new GeneralSettingResource($setting)]);
    }

    #[OA\Get(
        path: '/api/v1/general-settings/{id}',
        operationId: 'generalSettingsShow',
        tags: ['Settings'],
        summary: 'Detalle de una versión',
        description: 'Devuelve una vigencia con su effective_to derivado. Las versiones históricas siempre se muestran (RF-CAT-005: la historia es reproducible).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', required: true, schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Versión encontrada',
                content: new OA\JsonContent(required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralSettingVersion')]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(
                response: 404,
                description: 'Versión inexistente',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Not found.')]),
            ),
        ],
    )]
    public function show(int $id): JsonResponse
    {
        $setting = $this->settings->get($id);

        if ($setting === null) {
            abort(404, 'General settings version not found.');
        }

        return response()->json(['data' => new GeneralSettingResource($setting)]);
    }

    #[OA\Delete(
        path: '/api/v1/general-settings/{id}',
        operationId: 'generalSettingsDestroy',
        tags: ['Settings'],
        summary: 'Eliminar una vigencia no efectiva',
        description: 'Elimina una versión solo cuando todavía no ha entrado en vigor (effective_from en el futuro). Las versiones ya en vigor quedan protegidas para la reproducibilidad histórica de los cálculos (RN-007): responden 409. La FK de pension_cases.calculation_setting_id (Fase 3) reforzará esta regla en BD.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', required: true, schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Vigencia futura eliminada',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Future settings version deleted.')]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(
                response: 409,
                description: 'La versión ya está en vigor y no puede eliminarse',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'The version effective from 2026-06-01 is already in effect and cannot be deleted.')]),
            ),
            new OA\Response(
                response: 404,
                description: 'Versión inexistente',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Not found.')]),
            ),
        ],
    )]
    public function destroy(int $id): JsonResponse
    {
        try {
            $deleted = $this->settings->delete($id);
        } catch (VersionAlreadyEffectiveException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        if (! $deleted) {
            abort(404, 'General settings version not found.');
        }

        return response()->json(['message' => 'Future settings version deleted.']);
    }
}
