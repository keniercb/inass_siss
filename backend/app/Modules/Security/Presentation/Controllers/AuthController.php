<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Controllers;

use App\Models\User;
use App\Modules\Security\Presentation\Requests\LoginRequest;
use App\Modules\Security\Presentation\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Phase 0 authentication skeleton (RF-SEG-001).
 *
 * Issues Sanctum personal access tokens on login. The hardening pass
 * (brute-force lockout policies, password lifecycle, token scopes) belongs
 * to phase 6 and is tracked there; only basic rate limiting is active now.
 */
final class AuthController
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if ($user === null || ! Hash::check($request->validated('password'), $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $token = $user->createToken('login');

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'user' => new UserResource($user),
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

        $user->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Token revoked.',
        ]);
    }
}
