#!/usr/bin/env bash
# Atomic recovery + fix for the Service+Repository refactor (Task 6).
# Runs everything in ONE bash invocation so the sandbox auto-commit
# daemon cannot flip HEAD mid-sequence. Untracked file: survives flips.
set -euo pipefail

cd /home/z/my-project/backend
PHP=~/.local/bin/php

echo "== 1. Restaurar rama sobre el commit del daemon (4f1c186) =="
git checkout -B feat/SGP-1-service-repository 4f1c186

echo "== 2. Fix: Laravel\\Sanctum\\AccessToken no existe; es PersonalAccessToken =="
$PHP -r '
$path = "app/Modules/Security/Infrastructure/Persistence/EloquentUserRepository.php";
$c = file_get_contents($path);
$c = str_replace("use Laravel\\Sanctum\\AccessToken;", "use Laravel\\Sanctum\\PersonalAccessToken;", $c);
$c = str_replace(
    "if (\$token instanceof AccessToken) {",
    "// The guard resolves bearer tokens to PersonalAccessToken models;\n        // TransientToken (session auth) never reaches this API route.\n        if (\$token instanceof PersonalAccessToken) {",
    $c
);
file_put_contents($path, $c);
echo "patched\n";
'
rg -n "PersonalAccessToken" app/Modules/Security/Infrastructure/Persistence/EloquentUserRepository.php

echo "== 3. QA: Pint (fix) -> PHPStan -> deptrac -> Pest (MySQL real) =="
$PHP vendor/bin/pint 2>&1 | tail -3
$PHP vendor/bin/phpstan analyse 2>&1 | tail -3
$PHP vendor/bin/deptrac analyse --fail-on-uncovered 2>&1 | tail -12
$PHP vendor/bin/pest 2>&1 | tail -4

echo "== 4. Amend con mensaje proper + push al remoto (a prueba de daemon) =="
git add -A
git commit --amend -m "feat(architecture): materializa el patron Service + Repository (ADR-11)

- Security: UserRepositoryInterface (Application/Contracts) y
  EloquentUserRepository (Infrastructure/Persistence), unico punto de
  acceso a users / personal_access_tokens
- AuthService (Application/Services) concentra login/logout (RF-SEG-001);
  DTO LoginResult readonly; Hasher inyectado en vez de facade
- AuthController delgado: valida -> delega -> envelope (RF-API-002)
- User movido a Infrastructure/Persistence/Models (Eloquent confinado,
  seccion 6 de la arquitectura); config/auth.php, factory y seeders
  actualizados; app/Models eliminado
- tests/Architecture/LayeringTest: conformidad R0-R4 del patron en CI
  (Presentation no consulta; Application sin facades/HTTP; Domain puro)
- AuthServiceTest unitario con repositorio in-memory: sin BD ni contenedor
- gen-modules.php: scaffold de 4 capas para los 12 modulos
- fix: instanceof PersonalAccessToken (AccessToken no existe en Sanctum 4)"
git push -f -u origin feat/SGP-1-service-repository

echo "== DONE =="
git log --oneline -2
