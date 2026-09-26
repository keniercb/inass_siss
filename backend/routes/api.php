<?php

declare(strict_types=1);

use App\Modules\Security\Presentation\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SGP API v1 (RF-API-001)
|--------------------------------------------------------------------------
| All endpoints live under the /api/v1 prefix (bootstrap/app.php).
| Response envelope follows RF-API-002: {"data": ...} on success,
| {"message": "..."} for single-message responses and HTTP 422 with
| field errors for validation.
*/

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
});
