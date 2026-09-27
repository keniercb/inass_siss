#!/usr/bin/env bash
# Crea el PR del slice RBAC del Sprint 3 (S3.4) vía REST API.
set -euo pipefail
PAT=$(grep -o 'ghp_[A-Za-z0-9]*' ~/.git-credentials | head -1)

BODY=$(cat <<'PR'
## RBAC base operativo — Sprint 3 parte 1 (S3.4, RF-SEG-002, ADR-18)

Materializa el control de acceso por roles declarado en la sección 2.2 de los requisitos con **spatie/laravel-permission 6.25**, organizado alrededor de una **matriz de dominio pura** como fuente única de verdad.

### Qué incluye
- **`PermissionMatrix`** (`Security/Domain/Authorization`): 5 roles institucionales (admin, director, specialist, operator, auditor) × 11 permisos iniciales `modulo.accion` (catalogs/settings view+manage, people view/create/edit/delete, audit.view/export, users.manage). Derivada de la tabla de uso de la sección 2.2; la fase 6 la extiende sin tocar consumidores.
- **`RolesAndPermissionsSeeder`**: idempotente por construcción (`firstOrCreate` + `syncPermissions`) — re-ejecutar converge las tablas exactamente a la matriz. `DemoUserSeeder` asigna `admin` a la cuenta demo.
- **`EnsurePermission`** middleware (alias `permission:`) propio del módulo Security: resuelve vía `Gate::before` del paquete (`checkPermissionTo`, que captura permisos inexistentes → 403 y no 500), guard-agnóstico con `auth:sanctum` delante.
- **Retrofit de rutas existentes**: grupos lectura (`*.view`) / escritura (`*.manage`) para catálogos, municipios, agencias y `general-settings`.
- **`/auth/me` y login** ahora exponen `roles` y `permissions` efectivos (UserResource + esquema OA).

### TDD
- 64 unit tests: la matriz **como dataset de Pest** (S3.4 — cada celda es un criterio de aceptación ejecutable) + invariants (admin total, auditor estrictamente de solo lectura, manage admin-exclusivo, operador registra personas).
- 15 feature tests: el estado esperado por rol **se deriva de la propia matriz**, nunca de una copia hardcodeada; incluye 401 anónimo, 403 sin rol, idempotencia del seeder.
- 44 tests existentes actualizados al helper `actingAsRole()` en `Tests\TestCase`.

### Criterios de aceptación cubiertos
- [x] RF-SEG-002 (M): roles del sistema + permisos por módulo/acción aplicados en backend (defense in depth: la autorización vive en el servidor)
- [x] S3.4: matriz rol-permiso como dataset de Pest que anticipa la matriz completa de la fase 6
- [ ] RF-SEG-003/004 (siguientes slices del sprint 3)

### QA local
Pint 178 files PASS · PHPStan 8 0 errores · deptrac 0 violaciones/0 uncovered · Pest 305 tests 0 fallos (305 = 226 previos + 79 nuevos) · suite Shared 76/121. La cobertura (≥80% global / ≥95% Shared) se valida en este pipeline con pcov.
PR
)

curl -sS -X POST \
  -H "Authorization: token ${PAT}" \
  -H "Accept: application/vnd.github+json" \
  https://api.github.com/repos/keniercb/inass_siss/pulls \
  -d "$(python3 -c "
import json, sys
print(json.dumps({'title': 'feat(security): Fase 1 Sprint 3 — RBAC base operativo con matriz de dominio (ADR-18)', 'head': 'feat/SGP-8-rbac', 'base': 'main', 'body': sys.stdin.read()}))
" <<< "$BODY")" | python3 -c "import json,sys; d=json.load(sys.stdin); print(d.get('html_url') or d)"
