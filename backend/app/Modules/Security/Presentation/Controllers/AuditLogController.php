<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Controllers;

use App\Modules\Security\Application\Contracts\AuditLogQueryInterface;
use App\Modules\Security\Application\DTO\AuditLogFilters;
use App\Modules\Security\Presentation\Requests\AuditLogIndexRequest;
use App\Modules\Security\Presentation\Resources\AuditLogResource;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read surface of the append-only bitácora (RF-AUD-003, ADR-19).
 *
 * Deliberately thin (ADR-11, ADR-12): the filters arrive validated
 * through AuditLogIndexRequest, the query sits behind the
 * AuditLogQueryInterface port (read-only: the trail has no mutation
 * pathway anywhere) and this layer only translates results into the
 * response envelope or the CSV export. The route guards the access
 * with permission:audit.view / audit.export (RF-SEG-002).
 */
final class AuditLogController
{
    private const int EXPORT_ROW_CAP = 10000;

    public function __construct(
        private readonly AuditLogQueryInterface $auditLogs,
    ) {}

    #[OA\Get(
        path: '/api/v1/audit-logs',
        operationId: 'auditLogsIndex',
        tags: ['Auditoría'],
        summary: 'Consultar la bitácora de acciones',
        description: 'Lista paginada y filtrable de la bitácora append-only (RF-AUD-003): filtros por usuario, sujeto, evento y rango de fechas. Requiere el permiso audit.view (rol Auditor o Administrador, sección 2.2).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\QueryParameter(name: 'causer_id', description: 'Filtro por usuario autor', schema: new OA\Schema(type: 'integer', format: 'int64', example: 1)),
            new OA\QueryParameter(name: 'subject_type', description: 'Filtro por clase del sujeto', schema: new OA\Schema(type: 'string')),
            new OA\QueryParameter(name: 'subject_id', description: 'Filtro por id del sujeto', schema: new OA\Schema(type: 'integer', format: 'int64')),
            new OA\QueryParameter(name: 'event', schema: new OA\Schema(type: 'string', enum: ['created', 'updated', 'deleted', 'restored'])),
            new OA\QueryParameter(name: 'from', description: 'Límite inferior inclusive (Y-m-d)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\QueryParameter(name: 'to', description: 'Límite superior inclusive (Y-m-d)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\QueryParameter(name: 'page', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\QueryParameter(name: 'per_page', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Entradas de bitácora paginadas (envoltura RF-API-002)',
                content: new OA\JsonContent(
                    required: ['data'],
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/AuditLogEntry'),
                        ),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
        ],
    )]
    public function index(AuditLogIndexRequest $request): JsonResponse
    {
        $paginator = $this->auditLogs->list($request->filters());

        return AuditLogResource::collection($paginator)->response();
    }

    #[OA\Get(
        path: '/api/v1/audit-logs/export',
        operationId: 'auditLogsExport',
        tags: ['Auditoría'],
        summary: 'Exportar la bitácora a CSV',
        description: 'Exportación CSV de la bitácora con los mismos filtros del listado (RF-AUD-003), para el rol Auditor. Requiere el permiso audit.export.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\QueryParameter(name: 'causer_id', schema: new OA\Schema(type: 'integer', format: 'int64')),
            new OA\QueryParameter(name: 'subject_type', schema: new OA\Schema(type: 'string')),
            new OA\QueryParameter(name: 'subject_id', schema: new OA\Schema(type: 'integer', format: 'int64')),
            new OA\QueryParameter(name: 'event', schema: new OA\Schema(type: 'string', enum: ['created', 'updated', 'deleted', 'restored'])),
            new OA\QueryParameter(name: 'from', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\QueryParameter(name: 'to', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Archivo CSV con las entradas filtradas (máximo 10 000 filas)',
                content: new OA\MediaType(mediaType: 'text/csv'),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
        ],
    )]
    public function export(AuditLogIndexRequest $request): StreamedResponse
    {
        $filters = $request->filters();

        return response()->streamDownload(
            function () use ($filters): void {
                $out = fopen('php://output', 'w');

                if ($out === false) {
                    return;
                }

                fputcsv($out, [
                    'id', 'event', 'causer_id', 'subject_type', 'subject_id',
                    'old', 'changes', 'request_id', 'created_at',
                ]);

                $page = 1;
                $exported = 0;

                do {
                    $paginator = $this->auditLogs->list($this->exportPage($filters, $page));

                    foreach ($paginator->items() as $entry) {
                        fputcsv($out, [
                            $entry->id,
                            $entry->event,
                            $entry->causerId,
                            $entry->subjectType,
                            $entry->subjectId,
                            $entry->old !== null ? json_encode($entry->old) : null,
                            $entry->changes !== null ? json_encode($entry->changes) : null,
                            $entry->requestId,
                            $entry->createdAt->format('Y-m-d H:i:s'),
                        ]);

                        $exported++;
                    }

                    $page++;
                } while ($exported < self::EXPORT_ROW_CAP && $page <= $paginator->lastPage());

                fclose($out);
            },
            'audit-logs-'.now()->format('Ymd-His').'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    private function exportPage(AuditLogFilters $filters, int $page): AuditLogFilters
    {
        return new AuditLogFilters(
            causerId: $filters->causerId,
            subjectType: $filters->subjectType,
            subjectId: $filters->subjectId,
            event: $filters->event,
            from: $filters->from,
            to: $filters->to,
            page: $page,
            perPage: 100,
        );
    }
}
