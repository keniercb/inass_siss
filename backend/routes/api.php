<?php

declare(strict_types=1);

use App\Modules\Catalogs\Presentation\Controllers\AgencyController;
use App\Modules\Catalogs\Presentation\Controllers\CatalogController;
use App\Modules\Catalogs\Presentation\Controllers\MunicipalityController;
use App\Modules\LegalBasis\Presentation\Controllers\LegalBasisController;
use App\Modules\Organizations\Presentation\Controllers\AuthorizedSignatureController;
use App\Modules\Organizations\Presentation\Controllers\EntityController;
use App\Modules\Organizations\Presentation\Controllers\OfficeController;
use App\Modules\PensionCases\Presentation\Controllers\PensionCaseController;
use App\Modules\People\Presentation\Controllers\PersonController;
use App\Modules\Security\Presentation\Controllers\AuditLogController;
use App\Modules\Security\Presentation\Controllers\AuthController;
use App\Modules\Security\Presentation\Controllers\PermissionController;
use App\Modules\Security\Presentation\Controllers\RoleController;
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
    // Renovación voluntaria de la propia contraseña (RF-SEG-001,
    // ADR-24): any authenticated account may renew its secret; the
    // service verifies the current one and revokes every OTHER
    // session (this token survives).
    Route::post('/auth/password', [AuthController::class, 'changePassword'])
        ->name('auth.change-password');
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

// Cuentas de usuario (S3.5 + S3.6, RF-SEG-001/004, RF-AUD-004,
// ADR-24): the account directory and detail answer to users.view
// (Administrador + Auditor, strictly read-only for the Auditor),
// while the lifecycle writes —creation, edition, deactivation,
// restoration, unlock, password reset and the user ↔ person
// association— answer to users.manage (Administrador). Every write
// lands in the append-only bitácora with secrets redacted.
Route::middleware(['auth:sanctum', 'permission:users.view'])->group(function (): void {
    Route::get('/users', [UserController::class, 'index'])
        ->name('users.index');
    Route::get('/users/{id}', [UserController::class, 'show'])
        ->whereNumber('id')
        ->name('users.show');
});

Route::middleware(['auth:sanctum', 'permission:users.manage'])->group(function (): void {
    Route::post('/users', [UserController::class, 'store'])
        ->name('users.store');
    Route::patch('/users/{id}', [UserController::class, 'update'])
        ->whereNumber('id')
        ->name('users.update');
    Route::delete('/users/{id}', [UserController::class, 'destroy'])
        ->whereNumber('id')
        ->name('users.destroy');
    Route::post('/users/{id}/restore', [UserController::class, 'restore'])
        ->whereNumber('id')
        ->name('users.restore');
    Route::post('/users/{id}/unlock', [UserController::class, 'unlock'])
        ->whereNumber('id')
        ->name('users.unlock');
    Route::patch('/users/{id}/password', [UserController::class, 'resetPassword'])
        ->whereNumber('id')
        ->name('users.reset-password');
    Route::post('/users/{id}/person', [UserController::class, 'linkPerson'])
        ->whereNumber('id')
        ->name('users.link-person');
    Route::delete('/users/{id}/person', [UserController::class, 'unlinkPerson'])
        ->whereNumber('id')
        ->name('users.unlink-person');
});

// Gestión de roles (RF-SEG-002, ADR-26): the directory answers to
// roles.view (Administrador + Auditor — strictly read-only for the
// Auditor, who resolves bitácora subjects), while the custom role
// lifecycle —creation, edition, deletion— answers to roles.manage
// (Administrador). Institutional roles are immutable: the writes
// answer 422 before touching the database. Every write lands in the
// append-only bitácora, with explicit entries for the permission
// pivots the Eloquent events cannot see.
Route::middleware(['auth:sanctum', 'permission:roles.view'])->group(function (): void {
    Route::get('/roles', [RoleController::class, 'index'])
        ->name('roles.index');
    Route::get('/roles/{id}', [RoleController::class, 'show'])
        ->whereNumber('id')
        ->name('roles.show');

    // Catálogo de permisos (RF-SEG-002, ADR-27): the read-only
    // surface the role editor consumes — every assignable permission
    // with its module.action decomposition, the institutional
    // holders (from the matrix) and the custom holders (live
    // pivots), plus the count of accounts that can act on it.
    // Permissions are code artifacts — the PermissionMatrix is
    // their single source of truth — so no write route exists:
    // creating one at runtime would desynchronize code from
    // database. The route pattern only admits lowercase
    // modulo.accion names; anything else 404s at routing.
    Route::get('/permissions', [PermissionController::class, 'index'])
        ->name('permissions.index');
    Route::get('/permissions/{permission}', [PermissionController::class, 'show'])
        ->where('permission', '[a-z][a-z0-9_]*\.[a-z]+')
        ->name('permissions.show');
});

Route::middleware(['auth:sanctum', 'permission:roles.manage'])->group(function (): void {
    Route::post('/roles', [RoleController::class, 'store'])
        ->name('roles.store');
    Route::patch('/roles/{id}', [RoleController::class, 'update'])
        ->whereNumber('id')
        ->name('roles.update');
    Route::delete('/roles/{id}', [RoleController::class, 'destroy'])
        ->whereNumber('id')
        ->name('roles.destroy');
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

// Expedientes (Fase 3 Sprint 5, RF-EXP-001..004): the aggregate root
// of the PensionCases module. Reads answer to cases.view (specialist,
// director and auditor consult; operator keeps it to review what it
// captured), creation answers to cases.create (operator: data capture
// registers cases in estado Solicitud per section 2.2) and the
// subrecord highs/removals answer to cases.edit while the case stays
// in submitted (plan S5.4). Transitions arrive in S6 with their own
// review/approve/reject permissions.
Route::middleware(['auth:sanctum', 'permission:cases.view'])->group(function (): void {
    Route::get('/pension-cases', [PensionCaseController::class, 'index'])
        ->name('pension-cases.index');
    Route::get('/pension-cases/{id}', [PensionCaseController::class, 'show'])
        ->whereNumber('id')
        ->name('pension-cases.show');
});

Route::middleware(['auth:sanctum', 'permission:cases.create'])->group(function (): void {
    Route::post('/pension-cases', [PensionCaseController::class, 'store'])
        ->name('pension-cases.store');
});

Route::middleware(['auth:sanctum', 'permission:cases.edit'])->group(function (): void {
    Route::post('/pension-cases/{id}/salary-records', [PensionCaseController::class, 'addSalaryRecord'])
        ->whereNumber('id')
        ->name('pension-cases.salary-records.store');
    Route::delete('/pension-cases/{id}/salary-records/{record}', [PensionCaseController::class, 'removeSalaryRecord'])
        ->whereNumber(['id', 'record'])
        ->name('pension-cases.salary-records.destroy');
    Route::post('/pension-cases/{id}/service-records', [PensionCaseController::class, 'addServiceRecord'])
        ->whereNumber('id')
        ->name('pension-cases.service-records.store');
    Route::delete('/pension-cases/{id}/service-records/{record}', [PensionCaseController::class, 'removeServiceRecord'])
        ->whereNumber(['id', 'record'])
        ->name('pension-cases.service-records.destroy');
    Route::post('/pension-cases/{id}/work-cycles', [PensionCaseController::class, 'addWorkCycle'])
        ->whereNumber('id')
        ->name('pension-cases.work-cycles.store');
    Route::delete('/pension-cases/{id}/work-cycles/{record}', [PensionCaseController::class, 'removeWorkCycle'])
        ->whereNumber(['id', 'record'])
        ->name('pension-cases.work-cycles.destroy');
});
