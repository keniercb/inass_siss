<?php

declare(strict_types=1);

use App\Modules\Catalogs\Presentation\Controllers\AgencyController;
use App\Modules\Catalogs\Presentation\Controllers\CatalogController;
use App\Modules\Catalogs\Presentation\Controllers\MunicipalityController;
use App\Modules\LegalBasis\Presentation\Controllers\LegalBasisController;
use App\Modules\Organizations\Presentation\Controllers\AuthorizedSignatureController;
use App\Modules\Organizations\Presentation\Controllers\EntityController;
use App\Modules\Organizations\Presentation\Controllers\OfficeController;
use App\Modules\People\Presentation\Controllers\PersonController;
use App\Modules\Security\Presentation\Controllers\AuditLogController;
use App\Modules\Security\Presentation\Controllers\AuthController;
use App\Modules\Security\Presentation\Controllers\UserController;
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

    // Restauración de entradas desactivadas (RF-AUD-004): admin-only
    // (catalogs.manage) and audited through the restored event.
    Route::post('/catalogs/{type}/{id}/restore', [CatalogController::class, 'restore'])
        ->whereNumber('id')
        ->name('catalogs.restore');

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

// Bitácora de acciones (Fase 1 Sprint 3, RF-AUD-003, ADR-19): the
// trail is append-only (RF-AUD-001), so the surface is strictly
// read-only — no update or delete route exists by design. Reading
// answers to audit.view, the CSV export to audit.export (sección 2.2:
// Auditor has both; the matrix is the single source).
Route::middleware(['auth:sanctum', 'permission:audit.view'])->group(function (): void {
    Route::get('/audit-logs', [AuditLogController::class, 'index'])
        ->name('audit-logs.index');
});

Route::middleware(['auth:sanctum', 'permission:audit.export'])->group(function (): void {
    Route::get('/audit-logs/export', [AuditLogController::class, 'export'])
        ->name('audit-logs.export');
});

// Personas (Fase 1 Sprint 3, RF-PER-001..005, S3.1-S3.3): reads
// answer to people.view, registration to people.create, edition to
// people.edit and deactivation to people.delete — operador holds
// create/edit/view, auditor is read-only (PermissionMatrix, ADR-18).
// Death registration (RF-PER-003) is an edit-scope lifecycle action
// with its own audited endpoint, never a plain PATCH field.
Route::middleware(['auth:sanctum', 'permission:people.view'])->group(function (): void {
    Route::get('/people', [PersonController::class, 'index'])
        ->name('people.index');
    Route::get('/people/{id}', [PersonController::class, 'show'])
        ->whereNumber('id')
        ->name('people.show');
});

Route::middleware(['auth:sanctum', 'permission:people.create'])->group(function (): void {
    Route::post('/people', [PersonController::class, 'store'])
        ->name('people.store');
});

Route::middleware(['auth:sanctum', 'permission:people.edit'])->group(function (): void {
    Route::patch('/people/{id}', [PersonController::class, 'update'])
        ->whereNumber('id')
        ->name('people.update');
    Route::post('/people/{id}/death', [PersonController::class, 'registerDeath'])
        ->whereNumber('id')
        ->name('people.death');
});

Route::middleware(['auth:sanctum', 'permission:people.delete'])->group(function (): void {
    Route::delete('/people/{id}', [PersonController::class, 'destroy'])
        ->whereNumber('id')
        ->name('people.destroy');
});

// Cuentas de usuario (Fase 1 Sprint 3, S3.5, RF-SEG-004): the
// user ↔ person association answers to users.manage (Administrador
// by the PermissionMatrix). Link and unlink are idempotent writes
// audited through the observers already watching User (ADR-19);
// uniqueness is the application rule with the users.person_id
// UNIQUE constraint as the backstop.
Route::middleware(['auth:sanctum', 'permission:users.manage'])->group(function (): void {
    Route::post('/users/{id}/person', [UserController::class, 'linkPerson'])
        ->whereNumber('id')
        ->name('users.link-person');
    Route::delete('/users/{id}/person', [UserController::class, 'unlinkPerson'])
        ->whereNumber('id')
        ->name('users.unlink-person');
});

// Estructura organizacional (Fase 2 Sprint 4, RF-ENT-001..005):
// reads —including the hierarchy trees (RF-ENT-005)— answer to
// organizations.view, held by every consultation role, while writes
// answer to organizations.manage, exclusive to admin in the
// PermissionMatrix (ADR-22). The acyclicity rule (RN-003) and the
// geographic coherence (RN-004) are service-level invariants backed
// by database constraints; every write lands in the audit trail.
Route::middleware(['auth:sanctum', 'permission:organizations.view'])->group(function (): void {
    Route::get('/entities', [EntityController::class, 'index'])
        ->name('entities.index');
    Route::get('/entities/tree', [EntityController::class, 'tree'])
        ->name('entities.tree');
    Route::get('/entities/{id}', [EntityController::class, 'show'])
        ->whereNumber('id')
        ->name('entities.show');

    Route::get('/offices', [OfficeController::class, 'index'])
        ->name('offices.index');
    Route::get('/offices/tree', [OfficeController::class, 'tree'])
        ->name('offices.tree');
    Route::get('/offices/{id}', [OfficeController::class, 'show'])
        ->whereNumber('id')
        ->name('offices.show');

    Route::get('/authorized-signatures', [AuthorizedSignatureController::class, 'index'])
        ->name('signatures.index');
    Route::get('/authorized-signatures/{id}', [AuthorizedSignatureController::class, 'show'])
        ->whereNumber('id')
        ->name('signatures.show');
});

Route::middleware(['auth:sanctum', 'permission:organizations.manage'])->group(function (): void {
    Route::post('/entities', [EntityController::class, 'store'])
        ->name('entities.store');
    Route::patch('/entities/{id}', [EntityController::class, 'update'])
        ->whereNumber('id')
        ->name('entities.update');
    Route::delete('/entities/{id}', [EntityController::class, 'destroy'])
        ->whereNumber('id')
        ->name('entities.destroy');

    Route::post('/offices', [OfficeController::class, 'store'])
        ->name('offices.store');
    Route::patch('/offices/{id}', [OfficeController::class, 'update'])
        ->whereNumber('id')
        ->name('offices.update');
    Route::delete('/offices/{id}', [OfficeController::class, 'destroy'])
        ->whereNumber('id')
        ->name('offices.destroy');

    Route::post('/authorized-signatures', [AuthorizedSignatureController::class, 'store'])
        ->name('signatures.store');
    Route::patch('/authorized-signatures/{id}', [AuthorizedSignatureController::class, 'update'])
        ->whereNumber('id')
        ->name('signatures.update');
    Route::delete('/authorized-signatures/{id}', [AuthorizedSignatureController::class, 'destroy'])
        ->whereNumber('id')
        ->name('signatures.destroy');
});

// Base legal (Fase 2 Sprint 4, RF-LEG-002..004): reads answer to
// legalbases.view — the selector of vigentes that the case approval
// will consume (RF-LEG-003) — while writes answer to
// legalbases.manage, exclusive to admin in the PermissionMatrix
// (ADR-23). The tern type-number-year is unique with the year
// derived from the issue date (H-11); the derogation is an auditable
// date edit and the validity is derived at read time.
Route::middleware(['auth:sanctum', 'permission:legalbases.view'])->group(function (): void {
    Route::get('/legal-bases', [LegalBasisController::class, 'index'])
        ->name('legal-bases.index');
    Route::get('/legal-bases/{id}', [LegalBasisController::class, 'show'])
        ->whereNumber('id')
        ->name('legal-bases.show');
});

Route::middleware(['auth:sanctum', 'permission:legalbases.manage'])->group(function (): void {
    Route::post('/legal-bases', [LegalBasisController::class, 'store'])
        ->name('legal-bases.store');
    Route::patch('/legal-bases/{id}', [LegalBasisController::class, 'update'])
        ->whereNumber('id')
        ->name('legal-bases.update');
    Route::delete('/legal-bases/{id}', [LegalBasisController::class, 'destroy'])
        ->whereNumber('id')
        ->name('legal-bases.destroy');
});
