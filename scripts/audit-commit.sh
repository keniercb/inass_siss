#!/usr/bin/env bash
# Commit atómico de los campos de auditoría en users (Task 9, ADR-14).
# Una sola invocación para minimizar la ventana del daemon que cambia HEAD.
set -euo pipefail
cd /home/z/my-project

# 1) Asegurar la rama correcta (el daemon la revierte a main entre llamadas).
git checkout -B feat/SGP-4-users-audit-fields origin/main

# 2) Stage completo: código + migración + docs + este script.
git add backend/app/Modules/Shared \
        backend/app/Modules/Security \
        backend/database/migrations/2026_09_26_111916_add_audit_fields_to_users_table.php \
        "download/02_Diseno_de_arquitectura.md" \
        "download/Diseño de arquitectura.md" \
        "download/03_Modelo_de_datos.md" \
        "download/Modelo de datos.md" \
        scripts/audit-commit.sh

# 3) Verificación previa al commit: solo los archivos esperados.
echo "=== staged ==="
git diff --cached --stat | tail -16

# 4) Commit.
git commit -m "feat(security): campos de auditoría en users con estampado automático (ADR-14)" \
           -m "Migración: created_by/updated_by (FK autoreferencial a users, restrictOnDelete)
+ deleted_at (soft delete; las cuentas borradas no pueden autenticarse).
Modelo User: SoftDeletes, fillable, casts y relaciones creator()/updater().
Estampado automático genérico: Shared\Contracts\CurrentUserProviderInterface
(puerto del actor) + Shared\Support\AuditableObserver (crea/modifica estampa el
autor; preserva valores explícitos de seeders/imports; el contexto anónimo/CLI
nunca borra historia), con implementación en Security\Infrastructure\
\Authentication\AuthenticatedUserIdProvider (guard por defecto + sanctum) y
registro en SecurityServiceProvider vía Model::observe (resolución por
contenedor, DIP). Plantilla lista para Fase 1+.
TDD: 13 tests nuevos (6 unit del observer + 7 feature: columnas, estampado con
actingAs web/sanctum, anónimo, soft delete => login 401, wiring del contrato).
QA: Pint, PHPStan 8, deptrac 0 violaciones, Pest 103/103, suite Shared 74/74.
Docs: ADR-14 + v1.4 en ambas copias de arquitectura; entrada users del Modelo
de datos actualizada (ambas copias)."

echo "=== resultado ==="
git symbolic-ref HEAD
git log --oneline -2
git status --short | head -5

# 5) Push (fallará sin PAT, pero deja constancia del intento).
git push -u origin feat/SGP-4-users-audit-fields 2>&1 | tail -3 || echo "PUSH FALLIDO: sin credenciales"
