#!/usr/bin/env python3
"""Parche de implementación SGP-34 (Task 40): DELETE soft + PUT promovente inmutable.

Aplica los fragmentos de scripts/task40/ sobre el árbol del backend con
anclas exactas e idempotencia (re-ejecutable sin duplicar). Cada parche
verifica unicidad del ancla y aborta sin tocar nada si algo no encaja.
"""
import sys
from pathlib import Path

ROOT = Path("/home/z/my-project/backend")
FRAG = Path("/home/z/my-project/scripts/task40")

applied = []


def frag(name: str) -> str:
    return (FRAG / name).read_text(encoding="utf-8")


def patch(path: str, old: str, new: str, label: str, count: int = 1) -> None:
    p = ROOT / path
    content = p.read_text(encoding="utf-8")
    if new in content and old not in content:
        applied.append(f"[idempotente] {label}")
        return
    found = content.count(old)
    if found != count:
        print(f"FALLO ancla {label}: {found} ocurrencias (esperaba {count})")
        sys.exit(1)
    p.write_text(content.replace(old, new, count), encoding="utf-8")
    applied.append(f"[ok] {label}")


def insert_before(path: str, anchor: str, fragment: str, label: str,
                  back_to: str | None = None) -> None:
    """Inserta fragment antes del ancla (o antes de la línea que contiene
    back_to, buscada hacia atrás desde el ancla)."""
    p = ROOT / path
    content = p.read_text(encoding="utf-8")
    if fragment.strip() in content:
        applied.append(f"[idempotente] {label}")
        return
    idx = content.find(anchor)
    if idx == -1:
        print(f"FALLO ancla {label}: no encontrada")
        sys.exit(1)
    if back_to is not None:
        idx = content.rfind(back_to, 0, idx)
        if idx == -1:
            print(f"FALLO ancla retro {label}: no encontrada")
            sys.exit(1)
    content = content[:idx] + fragment + content[idx:]
    p.write_text(content, encoding="utf-8")
    applied.append(f"[ok] {label}")


def insert_after(path: str, anchor: str, fragment: str, label: str) -> None:
    p = ROOT / path
    content = p.read_text(encoding="utf-8")
    if fragment.strip() in content:
        applied.append(f"[idempotente] {label}")
        return
    idx = content.find(anchor)
    if idx == -1:
        print(f"FALLO ancla {label}: no encontrada")
        sys.exit(1)
    end = idx + len(anchor)
    content = content[:end] + fragment + content[end:]
    p.write_text(content, encoding="utf-8")
    applied.append(f"[ok] {label}")


# ------------------------------------------------------------------
# 1) Migración: quitar el ' NULL' previo a GENERATED ALWAYS AS (la
#    sintaxis que MySQL rechaza con 1064 — la nullability de una
#    generated column se deriva de la expresión, no se declara).
# ------------------------------------------------------------------
patch(
    "database/migrations/2026_10_02_130000_release_open_case_reservation_on_pension_cases_soft_delete.php",
    '" ADD COLUMN open_case_key BIGINT UNSIGNED NULL"',
    '" ADD COLUMN open_case_key BIGINT UNSIGNED"',
    "migración: NULL de más en GENERATED (up+down)",
    count=2,
)

# ------------------------------------------------------------------
# 2) ApiDoc.php: 1.1.0 -> 1.2.0 (señal de frescura de la spec).
# ------------------------------------------------------------------
patch(
    "app/OpenApi/ApiDoc.php",
    "version: '1.1.0',",
    "version: '1.2.0',",
    "ApiDoc version 1.2.0",
)

# ------------------------------------------------------------------
# 3) Rutas: PUT + DELETE en el grupo cases.edit.
# ------------------------------------------------------------------
insert_after(
    "routes/api.php",
    "Route::middleware(['auth:sanctum', 'permission:cases.edit'])->group(function (): void {\n",
    frag("frag_routes.txt"),
    "rutas PUT/DELETE del expediente",
)

# Comentario del bloque de expedientes: mencionar el ciclo de vida.
patch(
    "routes/api.php",
    "// subrecord highs/removals answer to cases.edit while the case stays\n"
    "// in submitted (plan S5.4). Transitions arrive in S6 with their own\n"
    "// review/approve/reject permissions.",
    "// subrecord highs/removals answer to cases.edit while the case stays\n"
    "// in submitted (plan S5.4). Since SGP-34 (user correction) the\n"
    "// aggregate itself is writable through cases.edit: PUT edits the\n"
    "// case fields (the promovente stays immutable) and DELETE\n"
    "// soft-deletes a submitted case, releasing the one-open-case\n"
    "// reservation. Transitions arrive in S6 with their own\n"
    "// review/approve/reject permissions.",
    "comentario del bloque de rutas",
)

# ------------------------------------------------------------------
# 4) Contrato del repository: update/delete.
# ------------------------------------------------------------------
insert_after(
    "app/Modules/PensionCases/Application/Contracts/PensionCaseRepositoryInterface.php",
    "public function create(array $attributes): PensionCase;",
    frag("frag_contract_repo.txt"),
    "contrato repo: update/delete",
)

# ------------------------------------------------------------------
# 5) Repository Eloquent: update/delete tras create().
# ------------------------------------------------------------------
insert_after(
    "app/Modules/PensionCases/Infrastructure/Persistence/EloquentPensionCaseRepository.php",
    "        return $case->refresh();\n    }\n",
    frag("frag_repo_methods.txt"),
    "repository: update/delete",
)

# ------------------------------------------------------------------
# 6) Service: UPDATE_COLUMNS, update/delete y helpers.
# ------------------------------------------------------------------
insert_after(
    "app/Modules/PensionCases/Application/Services/PensionCaseService.php",
    "        'termination_date',\n        'last_salary',\n    ];\n",
    frag("frag_service_const.txt"),
    "service: UPDATE_COLUMNS",
)

# Métodos update/delete tras el cierre de warnings().
insert_after(
    "app/Modules/PensionCases/Application/Services/PensionCaseService.php",
    "        return [\n            'missing_salary_years' => SalarySeries::missingConsecutiveYears($years),\n        ];\n    }\n",
    frag("frag_service_update.txt"),
    "service: update/delete",
)

# Helpers privados antes de caseOrNull.
insert_before(
    "app/Modules/PensionCases/Application/Services/PensionCaseService.php",
    "    private function caseOrNull(int $caseId): ?PensionCase",
    frag("frag_service_helpers.txt"),
    "service: helpers update",
)

# ------------------------------------------------------------------
# 7) Controller: import del request + métodos update/destroy.
# ------------------------------------------------------------------
patch(
    "app/Modules/PensionCases/Presentation/Controllers/PensionCaseController.php",
    "use App\\Modules\\PensionCases\\Presentation\\Requests\\StoreWorkCycleRequest;\n",
    "use App\\Modules\\PensionCases\\Presentation\\Requests\\StoreWorkCycleRequest;\n"
    "use App\\Modules\\PensionCases\\Presentation\\Requests\\UpdatePensionCaseRequest;\n",
    "controller: import UpdatePensionCaseRequest",
)

insert_before(
    "app/Modules/PensionCases/Presentation/Controllers/PensionCaseController.php",
    "path: '/api/v1/pension-cases/{id}/salary-records',",
    frag("frag_controller_update.txt"),
    "controller: update/destroy",
    back_to="    #[OA\Post(",
)

# ------------------------------------------------------------------
# 8) Docblock del modelo: la generated column ahora también libera
#    en soft-deleted.
# ------------------------------------------------------------------
patch(
    "app/Modules/PensionCases/Infrastructure/Persistence/Models/PensionCase.php",
    "is NULL on terminal states so the UNIQUE index admits many\n"
    " * resolved cases but at most one live case per applicant.",
    "is NULL on terminal states AND on soft-deleted rows (SGP-34: the\n"
    " * soft delete releases the one-open-case reservation) so the\n"
    " * UNIQUE index admits many resolved cases but at most one live\n"
    " * case per applicant.",
    "docblock modelo: reservación liberada en soft delete",
)

print("PATCHES APLICADOS:")
for a in applied:
    print(" ", a)
print(f"\nTotal: {len(applied)} parches OK")
