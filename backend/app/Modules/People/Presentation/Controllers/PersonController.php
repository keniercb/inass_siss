<?php

declare(strict_types=1);

namespace App\Modules\People\Presentation\Controllers;

use App\Modules\People\Application\Contracts\PeopleServiceInterface;
use App\Modules\People\Application\Exceptions\HomonymCandidatesException;
use App\Modules\People\Application\Exceptions\IdentityAlreadyRegisteredException;
use App\Modules\People\Domain\DuplicateCandidate;
use App\Modules\People\Infrastructure\Persistence\Models\Person;
use App\Modules\People\Presentation\Requests\PersonIndexRequest;
use App\Modules\People\Presentation\Requests\RegisterDeathRequest;
use App\Modules\People\Presentation\Requests\StorePersonRequest;
use App\Modules\People\Presentation\Requests\UpdatePersonRequest;
use App\Modules\People\Presentation\Resources\PersonResource;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * HTTP surface for people (RF-PER-001..005).
 *
 * Deliberately thin: validation arrives through the FormRequests,
 * the duplicate and lifecycle rules sit behind the
 * PeopleServiceInterface port and all data access lives in the
 * repository behind it. Duplicate control (RF-PER-005) is
 * conversational: an identity already registered answers 409 with
 * the registered person, and living homonym candidates answer 409
 * with the list until the request carries confirm=true. Death
 * registration (RF-PER-003) is its own audited action — never a
 * field edit — and deletion is a soft delete that keeps the identity
 * reserved (RN-001). The OA attributes keep the OpenAPI spec
 * attached to this surface (ADR-13).
 */
final class PersonController
{
    public function __construct(
        private readonly PeopleServiceInterface $people,
    ) {}

    #[OA\Get(
        path: '/api/v1/people',
        operationId: 'peopleIndex',
        tags: ['Personas'],
        summary: 'Búsqueda de personas',
        description: 'Búsqueda por prefijo de identidad (patrón ci_buscado%: acota con cada dígito tecleado), fragmentos de nombres/apellidos (combinables) y filtros básicos, paginada y ordenada por apellido/nombre/fecha de nacimiento (RF-PER-004). Los resultados incluyen fecha de nacimiento y padres para desambiguar homónimos.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\QueryParameter(name: 'identity', description: 'Prefijo del carné de identidad: 1 a 11 dígitos, ci_buscado% (11 dígitos equivale a la búsqueda exacta)', schema: new OA\Schema(type: 'string', minLength: 1, maxLength: 11)),
            new OA\QueryParameter(name: 'q', description: 'Fragmentos de nombre/apellido (cada palabra debe aparecer)', schema: new OA\Schema(type: 'string', maxLength: 120)),
            new OA\QueryParameter(name: 'sex', schema: new OA\Schema(type: 'string', enum: ['M', 'F'])),
            new OA\QueryParameter(name: 'deceased', schema: new OA\Schema(type: 'boolean')),
            new OA\QueryParameter(name: 'birth_from', description: 'Nacidos desde (Y-m-d, inclusivo)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\QueryParameter(name: 'birth_to', description: 'Nacidos hasta (Y-m-d, inclusivo)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\QueryParameter(name: 'page', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\QueryParameter(name: 'per_page', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resultados paginados con envelope RF-API-002',
                content: new OA\JsonContent(
                    required: ['data'],
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Person')),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'total', type: 'integer', example: 42),
                                new OA\Property(property: 'last_page', type: 'integer', example: 3),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function index(PersonIndexRequest $request): JsonResponse
    {
        $paginator = $this->people->search(
            $request->filters(),
            (int) $request->query('page', '1'),
            (int) $request->query('per_page', '15'),
        );

        return PersonResource::collection($paginator)->response();
    }

    #[OA\Get(
        path: '/api/v1/people/{id}',
        operationId: 'peopleShow',
        tags: ['Personas'],
        summary: 'Detalle de una persona',
        description: 'Devuelve la persona activa con ese id (las desactivadas responden 404, RN-001: su identidad sigue reservada).',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Persona',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Person'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 404, description: 'Persona inexistente o desactivada'),
        ],
    )]
    public function show(int $id): PersonResource
    {
        $person = $this->people->get($id);

        abort_if($person === null, 404, 'Person not found.');

        return new PersonResource($person);
    }

    #[OA\Post(
        path: '/api/v1/people',
        operationId: 'peopleStore',
        tags: ['Personas'],
        summary: 'Registro de una persona',
        description: 'Alta con validación estructural del carné (RN-001) y control de duplicados (RF-PER-005): identidad ya registrada responde 409 con la persona registrada (también contra desactivadas); homónimos vivos responden 409 con los candidatos hasta que la petición lleve confirm=true. La ficha única de ciudadano es opcional y única.',
        security: [['sanctumAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['identity_number', 'first_name', 'first_surname', 'sex', 'birth_date', 'address'],
                properties: [
                    new OA\Property(property: 'identity_number', type: 'string', example: '18506150012'),
                    new OA\Property(property: 'first_name', type: 'string', example: 'Juan'),
                    new OA\Property(property: 'middle_name', type: 'string', nullable: true, example: 'Carlos'),
                    new OA\Property(property: 'first_surname', type: 'string', example: 'Pérez'),
                    new OA\Property(property: 'second_surname', type: 'string', nullable: true, example: 'Gómez'),
                    new OA\Property(property: 'sex', type: 'string', enum: ['M', 'F'], example: 'M'),
                    new OA\Property(property: 'race_id', type: 'integer', nullable: true, example: 3),
                    new OA\Property(property: 'address', type: 'string', example: 'Calle 23 #45, Vedado, La Habana'),
                    new OA\Property(property: 'birth_date', type: 'string', format: 'date', example: '1985-06-15'),
                    new OA\Property(property: 'father_name', type: 'string', nullable: true),
                    new OA\Property(property: 'mother_name', type: 'string', nullable: true),
                    new OA\Property(property: 'citizen_card_id', type: 'string', nullable: true),
                    new OA\Property(property: 'confirm', type: 'boolean', nullable: true, example: false, description: 'Confirmación del aviso de homónimos (RF-PER-005)'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Persona registrada con autoría estampada',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Person'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
            new OA\Response(
                response: 409,
                description: 'Duplicado: identity_number ya registrada (devuelve la persona) u homónimos vivos (devuelve candidatos)',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'person', ref: '#/components/schemas/Person', nullable: true),
                        new OA\Property(
                            property: 'candidates',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'identity_number', type: 'string'),
                                    new OA\Property(property: 'first_name', type: 'string'),
                                    new OA\Property(property: 'first_surname', type: 'string'),
                                    new OA\Property(property: 'birth_date', type: 'string'),
                                ],
                                type: 'object',
                            ),
                            nullable: true,
                        ),
                    ],
                ),
            ),
        ],
    )]
    public function store(StorePersonRequest $request): JsonResponse
    {
        try {
            $person = $this->people->create($request->validated());
        } catch (IdentityAlreadyRegisteredException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'person' => new PersonResource($exception->person),
            ], 409);
        } catch (HomonymCandidatesException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'candidates' => array_map(
                    fn (DuplicateCandidate $candidate): array => [
                        'id' => $candidate->id,
                        'identity_number' => $candidate->identityNumber,
                        'first_name' => $candidate->firstName,
                        'first_surname' => $candidate->firstSurname,
                        'birth_date' => $candidate->birthDate,
                    ],
                    $exception->candidates,
                ),
            ], 409);
        }

        return (new PersonResource($person))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Patch(
        path: '/api/v1/people/{id}',
        operationId: 'peopleUpdate',
        tags: ['Personas'],
        summary: 'Edición de una persona',
        description: 'Edición parcial con auditoría de valores previos (RF-PER-002). El carné de identidad es inmutable (RN-001) y la fecha de fallecimiento se registra por su propio endpoint: ambos campos responden 422 si viajan aquí.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'first_name', type: 'string', example: 'Juan'),
                    new OA\Property(property: 'middle_name', type: 'string', nullable: true),
                    new OA\Property(property: 'first_surname', type: 'string', example: 'Pérez'),
                    new OA\Property(property: 'second_surname', type: 'string', nullable: true),
                    new OA\Property(property: 'sex', type: 'string', enum: ['M', 'F']),
                    new OA\Property(property: 'race_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'address', type: 'string', example: 'Calle 5 #12, Vedado, La Habana'),
                    new OA\Property(property: 'birth_date', type: 'string', format: 'date'),
                    new OA\Property(property: 'father_name', type: 'string', nullable: true),
                    new OA\Property(property: 'mother_name', type: 'string', nullable: true),
                    new OA\Property(property: 'citizen_card_id', type: 'string', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Persona actualizada (valores previos en la bitácora)',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Person'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
            new OA\Response(response: 404, description: 'Persona inexistente o desactivada'),
        ],
    )]
    public function update(UpdatePersonRequest $request, int $id): PersonResource
    {
        $person = $this->people->update($id, $request->validated());

        abort_if($person === null, 404, 'Person not found.');

        return new PersonResource($person);
    }

    #[OA\Post(
        path: '/api/v1/people/{id}/death',
        operationId: 'peopleRegisterDeath',
        tags: ['Personas'],
        summary: 'Registro de fallecimiento',
        description: 'Fija (o corrige) la fecha de fallecimiento (RF-PER-003): estrictamente posterior al nacimiento, nunca futura, datable y auditada con valores previos. Persona fallecida queda marcada como deceased en búsquedas y bloques para nuevos trámites.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['death_date'],
                properties: [
                    new OA\Property(property: 'death_date', type: 'string', format: 'date', example: '2024-03-10'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Fallecimiento registrado (o corregido)',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Person'),
                ]),
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
            new OA\Response(response: 404, description: 'Persona inexistente o desactivada'),
        ],
    )]
    public function registerDeath(RegisterDeathRequest $request, int $id): PersonResource
    {
        $person = $this->people->registerDeath($id, (string) $request->validated()['death_date']);

        abort_if($person === null, 404, 'Person not found.');

        return new PersonResource($person);
    }

    #[OA\Delete(
        path: '/api/v1/people/{id}',
        operationId: 'peopleDestroy',
        tags: ['Personas'],
        summary: 'Desactivación de una persona',
        description: 'Borrado lógico: la persona sale de búsquedas y detalle, pero su identidad queda reservada para siempre (RN-001) y el borrado queda auditado. La reactivación seguirá el patrón restore de ADR-19 cuando el módulo lo necesite.',
        security: [['sanctumAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Persona desactivada', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string', example: 'Person deactivated.')],
            )),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 404, description: 'Persona inexistente o ya desactivada'),
        ],
    )]
    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->people->delete($id);

        abort_if(! $deleted, 404, 'Person not found.');

        return response()->json(['message' => 'Person deactivated.']);
    }
}
