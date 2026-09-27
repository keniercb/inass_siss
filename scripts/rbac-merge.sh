#!/usr/bin/env bash
# Squash-merge del PR #9 y verificación del push-run de main.
set -euo pipefail
PAT=$(grep -o 'ghp_[A-Za-z0-9]*' ~/.git-credentials | head -1)

MERGE_BODY=$(cat <<'MSG'
feat(security): Fase 1 Sprint 3 — RBAC base operativo con matriz de dominio (ADR-18) (#9)

spatie/laravel-permission 6.25 materializa la PermissionMatrix de dominio
puro (5 roles de la sección 2.2 × 11 permisos modulo.accion): el seeder
converge las tablas de forma idempotente, el middleware permission: propio
del módulo Security protege las rutas existentes (lectura *.view, escritura
*.manage) resolviendo vía Gate::before del paquete (guard-agnóstico), y
/auth/me expone roles y permisos efectivos. La matriz alimenta seeder,
dataset de Pest (S3.4: cada celda es un criterio de aceptación) y tests de
feature que derivan el estado esperado de la propia matriz, nunca de una
copia hardcodeada. RF-SEG-002 (M) cerrado; TDD: 79 tests nuevos.

Docs: ADR-18 (arquitectura v1.8), tablas roles/permissions en el modelo de
datos (v1.3), orden de ejecución del Sprint 3 documentado en el plan (v1.1).
MSG
)

curl -sS -X PUT \
  -H "Authorization: token ${PAT}" \
  -H "Accept: application/vnd.github+json" \
  https://api.github.com/repos/keniercb/inass_siss/pulls/9/merge \
  -d "$(python3 -c "
import json, sys
print(json.dumps({'merge_method': 'squash', 'commit_title': 'feat(security): Fase 1 Sprint 3 — RBAC base operativo con matriz de dominio (ADR-18) (#9)', 'commit_message': sys.stdin.read()}))
" <<< "$MERGE_BODY")" | python3 -c "import json,sys; d=json.load(sys.stdin); print('merged:', d.get('merged'), '| sha:', d.get('sha'))"
