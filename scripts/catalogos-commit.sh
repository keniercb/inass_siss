#!/usr/bin/env bash
# Commit atómico de Fase 1 · Sprint 2 parte 1 (catálogos) — una sola invocación
# contra el daemon del sandbox (que puede cambiar HEAD entre comandos).
set -euo pipefail
cd /home/z/my-project

git symbolic-ref HEAD | grep -q "feat/SGP-5-catalogos" || { echo "ERROR: no estás en feat/SGP-5-catalogos"; exit 1; }

git add backend/app/Modules/Catalogs \
        backend/app/OpenApi/ApiDoc.php \
        backend/app/OpenApi/ApiResponses.php \
        backend/database/migrations \
        backend/database/seeders \
        backend/routes/api.php \
        backend/tests/Feature/ApiDocsTest.php \
        download/02_Diseno_de_arquitectura.md \
        "download/Diseño de arquitectura.md" \
        scripts/gen-catalog-scaffold.php \
        scripts/reprovision-sandbox.sh \
        worklog.md

git commit -m "$(cat <<'MSG'
feat(catalogs): Fase 1 Sprint 2 — catálogos con recurso genérico ADR-15

Módulo Catalogs completo (RF-CAT-001..004, RF-CAT-006):
- 18 tablas de catálogo con unicidades RN-008 en BD, autoría ADR-14
  y desactivación lógica; municipios con clave compuesta (RF-CAT-002)
  y agencias con coherencia RN-04 garantizada por FK compuesta.
- Recurso genérico /api/v1/catalogs/{type} dirigido por CatalogRegistry
  (ADR-15): un par de contratos cubre los 16 catálogos uniformes;
  municipios y agencias con servicios dedicados.
- Seeders Cuba idempotentes: 15 provincias / 168 municipios
  (Isla de la Juventud sin provincia) + 24 OACE y clasificadores
  de referencia (P-06 pendiente de validación).
- 84 tests nuevos (48 unit + 36 feature); ApiDocsTest amplía el
  contrato de documentación a las 6 rutas nuevas.
- Docs: ADR-15 + v1.5 en ambas copias de arquitectura.
MSG
)"

git log --oneline -1
git status --short | head -5
