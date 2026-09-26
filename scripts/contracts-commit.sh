#!/usr/bin/env bash
# Commit atómico del refactor Contracts-para-servicios (Task 7).
# Una sola invocación para minimizar la ventana del daemon que cambia HEAD.
set -euo pipefail

cd /home/z/my-project

# 1) Asegurar la rama correcta (el daemon la revierte a main entre llamadas).
git checkout -B feat/SGP-2-service-contracts f4804d0

# 2) Stage completo: código + docs + worklog.
git add backend/app/Modules/Security backend/tests/Architecture/LayeringTest.php \
        "download/02_Diseno_de_arquitectura.md" \
        "download/Diseño de arquitectura.md" \
        worklog.md

# 3) Verificación previa al commit: solo los archivos esperados.
echo "=== staged ==="
git diff --cached --stat | tail -10

# 4) Commit.
git commit -m "feat(architecture): contracts para las clases de servicio (ADR-12)" \
           -m "AuthServiceInterface en Application/Contracts como puerto del caso de uso;
AuthService la implementa; SecurityServiceProvider resuelve el binding;
AuthController inyecta el contrato en lugar de la clase concreta (DIP completo
en la frontera HTTP). LayeringTest ampliado: R5 impide que Presentation importe
servicios concretos y R6 rompe el build si un servicio aparece sin contrato.
Test de wiring del contenedor en AuthTest. QA: Pint, PHPStan 8, deptrac 0
violaciones, Pest 87 tests (79 passed + 8 warnings preexistentes) / 144
aserciones. Docs: ADR-12 + versión 1.2 en ambas copias."

echo "=== resultado ==="
git symbolic-ref HEAD
git log --oneline -2
git status --short | head -5

# 5) Push (fallará sin PAT, pero deja constancia del intento).
git push -u origin feat/SGP-2-service-contracts 2>&1 | tail -3 || echo "PUSH FALLIDO: sin credenciales (PAT perdido con el reset del sandbox)"
