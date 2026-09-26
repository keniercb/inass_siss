#!/usr/bin/env bash
# Atomic docs update: ADR-11 + worklog + commit + push (Task 6, docs part).
# Single bash invocation -> immune to the sandbox auto-commit daemon.
set -euo pipefail

cd /home/z/my-project
PHP=~/.local/bin/php

echo "== 1. Re-asertar rama desde el remoto (fuente segura) =="
git checkout -B feat/SGP-1-service-repository origin/feat/SGP-1-service-repository

echo "== 2. Insertar ADR-11 + nota 5.2 + version 1.1 en ambas copias =="
$PHP -r '
$copies = [
    "download/02_Diseno_de_arquitectura.md",
    "download/Diseño de arquitectura.md",
];

$adr10 = "| ADR-10 | deptrac en CI | Convención informal | Las fronteras de módulo que no se verifican se erosionan |\n";
$adr11 = "| ADR-11 | Service + Repository materializado en código y verificado con tests de arquitectura | Controllers con lógica y Eloquent embebidos (como quedó la Fase 0) | La separación documentada que no se verifica mecánicamente se erosiona; lección de la revisión de código de Fase 0 |\n";

$coroFin = "Este confinamiento es lo que permite probar el dominio (cálculo, estados) sin base de datos y a máxima velocidad.\n";
$coroNota = "\n**Materialización y enforcement (ADR-11, 2026-09-26).** El patrón dejó de ser solo convención: el módulo Security es la plantilla canónica (`Application/Contracts` para los puertos de repositorio, `Application/Services` para los casos de uso, `Application/DTO` para contratos de entrada/salida readonly, `Infrastructure/Persistence` para el único acceso a datos, y controllers que solo validan, delegan y traducen la respuesta). Las 4 capas están esqueletizadas en los 12 módulos (`scripts/gen-modules.php`) y `backend/tests/Architecture/LayeringTest.php` rompe el build en CI cuando alguien consulta la BD desde Presentation, usa facades/HTTP/queries en Application, ensucia Domain con Eloquent o importa Presentation desde Infrastructure. Los modelos viven en `Modules/<M>/Infrastructure/Persistence/Models` (el namespace raíz `App\\Models` quedó prohibido y verificado); el binding interfaz → implementación se registra en el `ServiceProvider` de cada módulo.\n";

$v10 = "| 1.0 | 2026-09-22 | Versión inicial: arquitectura, TDD y plan de fases | Arquitectura Backend |\n";
$v11 = "| 1.1 | 2026-09-26 | ADR-11: patrón Service + Repository materializado (Security como plantilla canónica) + enforcement con tests de arquitectura | Arq. Backend |\n";

foreach ($copies as $path) {
    $c = file_get_contents($path);
    if (! str_contains($c, "ADR-11")) {
        $c = str_replace($adr10, $adr10 . $adr11, $c);
        $c = str_replace($coroFin, $coroFin . $coroNota, $c);
        $c = str_replace($v10, $v10 . $v11, $c);
        file_put_contents($path, $c);
        echo "patched: $path\n";
    } else {
        echo "skip (ya tiene ADR-11): $path\n";
    }
}
'

echo "== 3. Verificar igualdad de copias =="
diff -q "download/02_Diseno_de_arquitectura.md" "download/Diseño de arquitectura.md" && echo "copias identicas"

echo "== 4. Worklog =="
cat >> worklog.md <<'WLOG'

---
Task ID: 6
Agent: Super Z (agente principal)
Task: Refactor del patrón Service + Repository — la revisión de código detectó que no se empleaban services ni repositories; separar lógica de negocio y acceso a datos de la capa de controllers y adoptarlo como patrón obligatorio para todo el desarrollo

Work Log:
- Patrón implementado en módulo Security (plantilla canónica conforme a secciones 4-6 de la arquitectura):
  - Application/Contracts/UserRepositoryInterface (puerto: findByEmail, issueAccessToken, revokeCurrentAccessToken)
  - Application/Services/AuthService (login/logout; Hasher inyectado como contrato en vez de facade)
  - Application/DTO/LoginResult (readonly: user + token)
  - Infrastructure/Persistence/EloquentUserRepository (único punto de acceso a users/personal_access_tokens)
  - Infrastructure/Persistence/Models/User (movido desde app/Models; newFactory() + docblock @property)
  - AuthController delgado: valida (LoginRequest) → delega en AuthService → null→401 / LoginResult→envelope RF-API-002
  - SecurityServiceProvider: bind UserRepositoryInterface → EloquentUserRepository (DIP)
- Enforcement: tests/Architecture/LayeringTest (R0-R4: sin App\Models raíz; Presentation no consulta BD; Application sin facades/HTTP/queries; Domain puro; Infrastructure no importa Presentation) + testsuite "Architecture" en phpunit.xml
- Tests: AuthServiceTest (4 tests unitarios sin BD ni contenedor, con InMemoryUserRepository + BcryptHasher rounds=4)
- scripts/gen-modules.php actualizado con estructura completa de 4 capas + .gitkeep y ejecutado (12 módulos; Shared con layout propio)
- Imports actualizados: UserResource, AuthTest, UserFactory (+$model explícito), DemoUserSeeder, config/auth.php; app/Models eliminado
- Bug real encontrado y corregido por los tests: instanceof contra Laravel\Sanctum\AccessToken (clase inexistente en Sanctum 4; la correcta es PersonalAccessToken) — el archivo estaba excluido de PHPStan por diseño; Pest lo detectó vía el test de revocación de token
- Incidentes del sandbox recuperados: (1) sesión rota con fallo persistente de herramientas (403) al iniciar el QA — trabajo persistido en disco y reanudado; (2) el daemon auto-commitó el refactor a main (4f1c186) y luego revierte HEAD/árbol a 9cac1a2 ENTRE llamadas de herramientas — mitigación: scripts atómicos de una sola invocación (scripts/fix-and-push.sh, scripts/docs-adr11.sh) y push inmediato al remoto
- QA final: Pint ✓, PHPStan 8 ✓, deptrac 0 violaciones/0 uncovered ✓, Pest 84 tests/141 assertions ✓ vs MySQL 8.4 real
- Commit 5e27e60 en rama feat/SGP-1-service-repository (push), PR #2 creado
- Docs: ADR-11 + nota de materialización en sección 5.2 + versión 1.1 en ambas copias del documento de arquitectura

Stage Summary:
- Patrón Service + Repository adoptado como obligatorio y verificado mecánicamente en CI (LayeringTest falla el build si se erosiona)
- Plantilla canónica en Security; 12 módulos esqueletizados con las 4 capas para Fase 1+
- Contrato HTTP intacto (AuthTest sin cambios de comportamiento): refactor sin impacto funcional
- PR #2: https://github.com/keniercb/inass_siss/pull/2
WLOG
echo "worklog actualizado"

echo "== 5. Commit + push =="
git add -A
git commit -m "docs(architecture): ADR-11 — Service + Repository materializado y enforcement en CI

- ADR-11 en el registro de decisiones (tabla ADR, sección 16)
- Nota de materialización y enforcement tras el corolario de persistencia (5.2)
- Versión 1.1 en el control de versiones del documento
- worklog: registro del refactor (Task 6) con incidentes y recuperación"
git push origin feat/SGP-1-service-repository

echo "== DONE =="
git log --oneline -3
