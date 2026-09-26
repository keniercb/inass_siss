<?php

declare(strict_types=1);

use App\Modules\Catalogs\Presentation\Controllers\AgencyController;
use App\Modules\Catalogs\Presentation\Controllers\CatalogController;
use App\Modules\Catalogs\Presentation\Controllers\MunicipalityController;
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

    // Catálogos (Fase 1, RF-CAT-001..004, RF-CAT-006): the generic
    // resource drives every uniform catalog from the CatalogRegistry
    // (ADR-15), while municipalities and agencies keep dedicated
    // endpoints for their specific rules (RF-CAT-002, RF-CAT-003).
    Route::get('/catalogs/{type}', [CatalogController::class, 'index'])
        ->name('catalogs.index');
    Route::post('/catalogs/{type}', [CatalogController::class, 'store'])
        ->name('catalogs.store');
    Route::get('/catalogs/{type}/{id}', [CatalogController::class, 'show'])
        ->whereNumber('id')
        ->name('catalogs.show');
    Route::patch('/catalogs/{type}/{id}', [CatalogController::class, 'update'])
        ->whereNumber('id')
        ->name('catalogs.update');
    Route::delete('/catalogs/{type}/{id}', [CatalogController::class, 'destroy'])
        ->whereNumber('id')
        ->name('catalogs.destroy');

    Route::apiResource('municipalities', MunicipalityController::class)
        ->only(['index', 'show', 'store', 'update', 'destroy']);
    Route::apiResource('agencies', AgencyController::class)
        ->only(['index', 'show', 'store', 'update', 'destroy']);
});
