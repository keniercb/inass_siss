<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Controllers;

use App\Modules\Security\Application\Contracts\AuthServiceInterface;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use App\Modules\Security\Presentation\Requests\LoginRequest;
use App\Modules\Security\Presentation\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Authentication HTTP surface (RF-SEG-001).
 *
 * Deliberately thin (ADR-11, ADR-12): validation arrives through
 * LoginRequest, the use cases sit behind the AuthServiceInterface
 * port and all data access lives in the repository behind it. This
 * layer only translates service outcomes into the response envelope
 * (RF-API-002) and nothing else; depending on the contract keeps the
 * controller unit-testable with a stub and lets decorators be wired
 * without touching HTTP code. The OA attributes keep the OpenAPI spec
 * attached to this surface (ADR-13).
 */
final class AuthController
{
    public function __construct(
        private readonly AuthServiceInterface $auth,
    ) {}

    #[OA\Post(
        path: '/api/v1/auth/login',
        operationId: 'authLogin',
        tags: ['Auth'],
        summary: 'Autenticar credenciales y emitir token',
        description: 'Verifica las credenciales y emite un token Bearer (RF-SEG-001). El email desconocido y la contraseña errónea colapsan en el mismo 401 para no revelar cuál falló. Limitado por throttle: 10 intentos por minuto.',
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Credenciales del usuario',
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@sgp.local'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Sesión iniciada: envelope RF-API-002 con token y usuario',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'token', type: 'string', example: '1|9QsTjXxaYl2nW8kJ'),
                                new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                                new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Credenciales inválidas',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Invalid credentials.'),
                    ],
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Payload inválido (campos requeridos o con formato incorrecto)',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'The email field is required.'),
                        new OA\Property(
                            property: 'errors',
                            type: 'object',
                            properties: [
                                new OA\Property(
                                    property: 'email',
                                    type: 'array',
                                    items: new OA\Items(type: 'string', example: 'The email field is required.'),
                                ),
                            ],
                        ),
                    ],
                ),
            ),
        ],
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            $request->validated('email'),
            $request->validated('password'),
        );

        if ($result === null) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        return response()->json([
            'data' => [
                'token' => $result->token,
                'token_type' => 'Bearer',
                'user' => new UserResource($result->user),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/auth/me',
        operationId: 'authMe',
        tags: ['Auth'],
        summary: 'Usuario autenticado',
        description: 'Devuelve el usuario asociado al token Bearer de la solicitud (RF-SEG-001).',
        security: [['sanctumAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Usuario autenticado',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/User'),
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, inválido o revocado',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.'),
                    ],
                ),
            ),
        ],
    )]
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/auth/logout',
        operationId: 'authLogout',
        tags: ['Auth'],
        summary: 'Revocar el token actual',
        description: 'Revoca el token Bearer usado en la solicitud y cierra la sesión (RF-SEG-001).',
        security: [['sanctumAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Token revocado',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Token revoked.'),
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, inválido o ya revocado',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.'),
                    ],
                ),
            ),
        ],
    )]
    public function logout(Request $request): JsonResponse
    {
        // The route is protected by auth:sanctum, so the user is always
        // resolved here; the assert documents the invariant for PHPStan.
        $user = $request->user();
        assert($user instanceof User);

        $this->auth->logout($user);

        return response()->json([
            'message' => 'Token revoked.',
        ]);
    }
}
