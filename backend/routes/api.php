<?php

declare(strict_types=1);

use App\Modules\Catalogs\Presentation\Controllers\AgencyController;
use App\Modules\Catalogs\Presentation\Controllers\CatalogController;
use App\Modules\Catalogs\Presentation\Controllers\MunicipalityController;
use App\Modules\Security\Presentation\Controllers\AuthController;
use App\Modules\Settings\Presentation\Controllers\GeneralSettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SGP API v1 (RF-API-001)
|--------------------------------------------------------------------------
| All endpoints live under the /api/v1 prefix (bootstrap/app.php).
| Response envelope follows RF-API-002: {"data": ...} on success,
| {"message": "..."} for single-message responses and HTTP 422 with
| field errors for validation.
|
| Every route below also declares its "modulo.accion" permission
| (RF-SEG-002, ADR-18): reads answer to *.view and writes to
| *.manage, resolved by the Security-owned EnsurePermission guard
| against the roles seeded from the PermissionMatrix (S3.4).
*/

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
});

Route::middleware(['auth:sanctum', 'permission:catalogs.view'])->group(function (): void {
    // Catálogos (Fase 1, RF-CAT-001..004, RF-CAT-006): the generic
    // resource drives every uniform catalog from the CatalogRegistry
    // (ADR-15), while municipalities and agencies keep dedicated
    // endpoints for their specific rules (RF-CAT-002, RF-CAT-003).
    Route::get('/catalogs/{type}', [CatalogController::class, 'index'])
        ->name('catalogs.index');
    Route::get('/catalogs/{type}/{id}', [CatalogController::class, 'show'])
        ->whereNumber('id')
        ->name('catalogs.show');

    Route::apiResource('municipalities', MunicipalityController::class)
        ->only(['index', 'show']);
    Route::apiResource('agencies', AgencyController::class)
        ->only(['index', 'show']);
});

Route::middleware(['auth:sanctum', 'permission:catalogs.manage'])->group(function (): void {
    Route::post('/catalogs/{type}', [CatalogController::class, 'store'])
        ->name('catalogs.store');
    Route::patch('/catalogs/{type}/{id}', [CatalogController::class, 'update'])
        ->whereNumber('id')
        ->name('catalogs.update');
    Route::delete('/catalogs/{type}/{id}', [CatalogController::class, 'destroy'])
        ->whereNumber('id')
        ->name('catalogs.destroy');

    Route::apiResource('municipalities', MunicipalityController::class)
        ->only(['store', 'update', 'destroy']);
    Route::apiResource('agencies', AgencyController::class)
        ->only(['store', 'update', 'destroy']);
});

Route::middleware(['auth:sanctum', 'permission:settings.view'])->group(function (): void {
    // Configuración general versionada (Fase 1, RF-CAT-005/RN-007):
    // versions are immutable, so the resource deliberately exposes no
    // update endpoint — corrections create a new vigencia. `current`
    // resolves the domain action (greatest effective_from <= date).
    Route::get('/general-settings/current', [GeneralSettingsController::class, 'current'])
        ->name('general-settings.current');
    Route::apiResource('general-settings', GeneralSettingsController::class)
        ->only(['index', 'show']);
});

Route::middleware(['auth:sanctum', 'permission:settings.manage'])->group(function (): void {
    Route::apiResource('general-settings', GeneralSettingsController::class)
        ->only(['store', 'destroy']);
});
