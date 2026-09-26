<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Controllers;

use App\Modules\Security\Application\Services\AuthService;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use App\Modules\Security\Presentation\Requests\LoginRequest;
use App\Modules\Security\Presentation\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authentication HTTP surface (RF-SEG-001).
 *
 * Deliberately thin (ADR-11): validation arrives through
 * LoginRequest, the use cases live in AuthService and all data
 * access sits behind the user repository port. This layer only
 * translates service outcomes into the response envelope
 * (RF-API-002) and nothing else.
 */
final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
    ) {}

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

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()),
        ]);
    }

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
