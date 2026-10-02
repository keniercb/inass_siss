#!/usr/bin/env python3
"""Task 40 (SGP-34) — parches de documentación.

Corrección de usuario: "Implementar un endpoint delete para expediente,
se puede eliminar siempre que este en estado de solicitud, la eliminacion
es softdelete. Implementar un endpoint put para expediente, no se pueden
modificar el promovente de la pension".

Cuatro documentos, parches con ancla única y aserción de unicidad (patrón
de las Tasks 34-39): RF-EXP-001 gana los dos ítems del ciclo de vida; la
tabla de endpoints de la arquitectura gana la fila PUT/DELETE del
agregado; el modelo de datos actualiza la semántica de open_case_key (la
reservación se libera en soft delete), su lista de migraciones y el
changelog 1.25; el plan amplía el ítem S5.2 y su changelog 1.23. Suite
1108/3788; fumiga HTTP del ciclo de vida de 28 comprobaciones TODO OK.
"""

from pathlib import Path

DOCS = Path(__file__).resolve().parent.parent / "download"


def patch(path: str, replacements: list[tuple[str, str]]) -> None:
    target = DOCS / path
    text = target.read_text(encoding="utf-8")
    for anchor, replacement in replacements:
        count = text.count(anchor)
        assert count == 1, (
            f"{path}: ancla con {count} ocurrencias (esperaba 1): {anchor[:90]!r}"
        )
        text = text.replace(anchor, replacement)
    target.write_text(text, encoding="utf-8")
    print(f"OK  {path}: {len(replacements)} parche(s)")


def add_row_after(path: str, prefix: str, new_row: str) -> None:
    """Añade new_row tras la línea que empieza por prefix (única)."""
    target = DOCS / path
    lines = target.read_text(encoding="utf-8").splitlines(keepends=True)
    matches = [i for i, line in enumerate(lines) if line.startswith(prefix)]
    assert len(matches) == 1, f"{path}: prefijo {prefix!r} con {len(matches)} ocurrencias"
    lines.insert(matches[0] + 1, new_row)
    target.write_text("".join(lines), encoding="utf-8")
    print(f"OK  {path}: fila añadida tras {prefix!r}")


# ---------------------------------------------------------------------------
# 1) Requisitos funcionales.md — RF-EXP-001: ítems del ciclo de vida
# ---------------------------------------------------------------------------
RF_ITEM38_TAIL = (
    "- [x] Fecha de desvinculación del promovente (corrección de usuario, Task 38/SGP-32): "
    "campo `termination_date` DATE NULL — opcional en el wire con la regla de forma Y-m-d "
    "única (422 con formato inválido; sin sonda semántica porque la corrección la declara "
    "opcional a secas; la omisión, null y '' persisten NULL) —: el alta la recibe "
    "(migración `2026_10_02_120000`) y el 201, el detalle y el listado la devuelven.\n"
)

RF_LIFECYCLE_ITEMS = RF_ITEM38_TAIL + (
    "- [x] Eliminación LÓGICA del expediente (corrección de usuario, Task 40/SGP-34): "
    "`DELETE /pension-cases/{id}` responde 200 SOLO mientras el expediente está en "
    "`submitted` (estado de solicitud) — fuera, 409 con el estado actual y nada eliminado — "
    "y la eliminación es SOFT: la fila sobrevive con su `deleted_at` (RN-001: evidencia y "
    "pista de auditoría siguen respondiendo, con los valores previos por ADR-19), los "
    "subregistros quedan físicos, el detalle y el listado públicos dejan de verlo (404; un "
    "segundo DELETE también 404) y la reservación de «un expediente abierto por persona» se "
    "LIBERA — la columna generada `open_case_key` pasa a NULL en las filas eliminadas "
    "(migración `2026_10_02_130000`) — para que el operador pueda re-capturar al mismo "
    "solicitante tras eliminar un registro equivocado.\n"
    "- [x] Edición del expediente con PROMOVENTE INMUTABLE (corrección de usuario, "
    "Task 40/SGP-34): `PUT /pension-cases/{id}` edita los campos del expediente propio "
    "(entidad empleadora, cargo, ambos pares de categorías, tipo y régimen de pensión, "
    "último salario y fecha de solicitud) SOLO en `submitted` (fuera, 409) con semántica "
    "PATCH (todo opcional, solo las claves declaradas cambian, la omisión nunca arranca el "
    "valor almacenado) y probes espejo del alta (entidad y catálogos activos, fecha no "
    "futura: 422); todo campo de la esfera de la persona — `applicant_person_id`, "
    "`filed_by_person_id`, par de Ejército Rebelde, `internationalist`, par de contacto y "
    "`termination_date` — responde 422 prohibido (el promovente de la pensión NO se puede "
    "modificar) y los campos de ciclo de vida (`office_id` regla 0, `number`, `status`) "
    "también 422.\n"
)

# ---------------------------------------------------------------------------
# 2) Diseño de arquitectura.md — fila de endpoints + changelog 1.32
# ---------------------------------------------------------------------------
ARQ_GET_ROW_TAIL = "el historial llega con las transiciones de S6 |\n"

ARQ_LIFECYCLE_ROW = (
    "| PUT/DELETE | `/api/v1/pension-cases/{id}` | `cases.edit` | Ciclo de vida del "
    "agregado (Task 40/SGP-34, corrección de usuario, RF-EXP-001): PUT edita los campos "
    "propios (entidad, cargo, ambos pares de categorías, tipo y régimen, último salario, "
    "fecha de solicitud) SOLO en `submitted` (fuera, 409 con el estado actual) con "
    "semántica PATCH y probes espejo del alta (entidad y catálogos activos, fecha no "
    "futura: 422) — el PROMOVENTE es INMUTABLE: todo campo de la esfera de la persona "
    "(applicant, filer, par de Ejército Rebelde, internacionalista, par de contacto, fecha "
    "de desvinculación) y los de ciclo de vida (`office_id` regla 0, `number`, `status`) "
    "responden 422 prohibido, jamás deriva silenciosa del promovente que el registro ya "
    "conoce —; DELETE es la eliminación LÓGICA de un caso `submitted` (200 «Case "
    "deleted.»): la fila sobrevive con `deleted_at` (RN-001) y la bitácora aterriza con "
    "los valores previos (ADR-19), los subregistros quedan físicos, el detalle y el "
    "listado dejan de verlo (404) y la reservación de un-abierto-por-persona se LIBERA "
    "(`open_case_key` NULL en filas borradas, migración `2026_10_02_130000`) |\n"
)

ARQ_CHANGELOG_132 = (
    "| 1.32 | 2026-10-02 | Corrección de usuario (Task 40, SGP-34): ciclo de vida del "
    "expediente — `PUT /pension-cases/{id}` edita los campos propios en `submitted` con "
    "semántica PATCH y probes espejo del alta mientras el PROMOVENTE queda INMUTABLE "
    "(todo campo de la esfera de la persona y los de ciclo de vida responden 422 "
    "prohibido), y `DELETE /pension-cases/{id}` soft-delete SOLO en `submitted` con la "
    "reservación de un-abierto-por-persona liberada (migración "
    "`2026_10_02_130000`: `open_case_key` NULL también en filas borradas) — fila de "
    "endpoints añadida; suite 1108/3788 contra MySQL real, Pint/PHPStan 8/deptrac en "
    "verde y fumiga HTTP del ciclo de vida de 28 comprobaciones TODO OK | Arq. Backend |\n"
)

# ---------------------------------------------------------------------------
# 3) Modelo de datos.md — semántica open_case_key + migraciones + changelog 1.25
# ---------------------------------------------------------------------------
MD_OPENCASE_ANCHOR = (
    "La unicidad de «un expediente abierto por persona» es FÍSICA: la columna generada "
    "almacenada `open_case_key` vale `IF(status IN ('approved','rejected'), NULL, "
    "applicant_person_id)` y su UNIQUE admite tantos casos resueltos como haga falta "
    "pero a lo sumo UNO vivo por proponente (el 409 del servicio con el expediente "
    "abierto la anticipa)."
)

MD_OPENCASE_NEW = (
    "La unicidad de «un expediente abierto por persona» es FÍSICA: la columna generada "
    "almacenada `open_case_key` vale `IF(status IN ('approved','rejected') OR deleted_at "
    "IS NOT NULL, NULL, applicant_person_id)` — expresión AMPLIADA por la migración "
    "`2026_10_02_130000_release_open_case_reservation_on_pension_cases_soft_delete` "
    "(Task 40/SGP-34, corrección de usuario): el DELETE del expediente es un soft delete "
    "disponible solo en `submitted`, y la fila borrada debe LIBERAR la reservación para "
    "que el operador pueda re-capturar al mismo solicitante tras eliminar un registro "
    "equivocado (la expresión original mantenía la clave en filas borradas bajo la "
    "premisa «ningún endpoint público borra expedientes», premisa que esta corrección "
    "retira; mantenerla habría convertido cada re-captura en una violación UNIQUE del "
    "driver — 500 — tras la sonda del servicio ya en verde) — y su UNIQUE admite tantos "
    "casos resueltos y BORRADOS como haga falta pero a lo sumo UNO vivo por proponente "
    "(el 409 del servicio con el expediente abierto la anticipa, y `findOpenCaseForPerson` "
    "ya excluía las filas borradas por el scope de SoftDeletes). El ciclo de vida del "
    "agregado (Task 40) añade el PUT de edición con el PROMOVENTE inmutable — todo campo "
    "de la esfera de la persona responde 422 prohibido en el wire — y el DELETE lógico "
    "solo en `submitted` (409 fuera, con el estado actual): la fila sobrevive con su "
    "`deleted_at`, los subregistros quedan físicos y la eliminación aterriza en la "
    "bitácora con los valores previos (ADR-19)."
)

MD_MIGS_ANCHOR = (
    "`2026_10_02_120000_add_termination_date_to_pension_cases_table`, "
    "`2026_10_02_120100_add_sector_to_pension_regimes_table` y "
    "`2026_10_02_120200_add_deceased_person_to_pension_types_table`):"
)

MD_MIGS_NEW = (
    "`2026_10_02_120000_add_termination_date_to_pension_cases_table`, "
    "`2026_10_02_120100_add_sector_to_pension_regimes_table`, "
    "`2026_10_02_120200_add_deceased_person_to_pension_types_table` y "
    "`2026_10_02_130000_release_open_case_reservation_on_pension_cases_soft_delete` "
    "(Task 40/SGP-34: la columna generada `open_case_key` se re-crea con la expresión "
    "ampliada que también anula la reservación en filas soft-deleted — MySQL exige soltar "
    "índice y columna antes de re-añadir ambos con la nueva expresión):"
)

MD_CHANGELOG_125 = (
    "| 1.25 | 2026-10-02 | Corrección de usuario (Task 40, SGP-34): ciclo de vida del "
    "expediente — `PUT /pension-cases/{id}` con el PROMOVENTE inmutable (los campos de la "
    "esfera de la persona y los de ciclo de vida responden 422 prohibido; semántica PATCH "
    "sobre los campos propios) y `DELETE /pension-cases/{id}` como soft delete SOLO en "
    "`submitted` con la reservación de un-abierto-por-persona liberada (migración "
    "`2026_10_02_130000`: `open_case_key` vale NULL también cuando `deleted_at` no es "
    "NULL) — semántica 5.7 y lista de migraciones actualizadas | Arq. Backend |\n"
)

# ---------------------------------------------------------------------------
# 4) 04_Plan_de_desarrollo.md — ítem S5.2 + changelog 1.23
# ---------------------------------------------------------------------------
PLAN_S52_TAIL = "; suite 1062/3562, fumiga con 4 comprobaciones propias"

PLAN_S52_NEW = (
    "; suite 1062/3562, fumiga con 4 comprobaciones propias "
    "✅ 2026-10-02 AMPLIADA por la corrección de usuario de Task 40 (SGP-34): ciclo de "
    "vida del agregado — PUT de edición con el PROMOVENTE INMUTABLE (todo campo de la "
    "esfera de la persona y los de ciclo de vida responden 422 prohibido; semántica PATCH "
    "sobre los campos propios con probes espejo del alta) y DELETE de eliminación LÓGICA "
    "solo en `submitted` (409 fuera, con el estado actual) con liberación de la "
    "reservación de un-abierto-por-persona — la migración `2026_10_02_130000` re-crea la "
    "columna generada `open_case_key` con la expresión que también anula la clave en "
    "filas soft-deleted (el trait SoftDeletes ya estaba en el modelo y `deleted_at` en la "
    "tabla desde el Sprint 5) —; suite 1108/3788, fumiga lifecycle propia "
    "(`smoke_case_lifecycle.php`) de 28 comprobaciones TODO OK"
)

PLAN_CHANGELOG_123 = (
    "| 1.23 | 2026-10-02 | Corrección de usuario (Task 40, SGP-34; ítem S5.2 ampliado): "
    "ciclo de vida del expediente — `PUT /pension-cases/{id}` edita los campos propios en "
    "`submitted` con semántica PATCH y probes espejo del alta mientras el PROMOVENTE "
    "queda INMUTABLE (todo campo de la esfera de la persona y los de ciclo de vida "
    "responden 422 prohibido), y `DELETE /pension-cases/{id}` soft-delete SOLO en "
    "`submitted` (la fila sobrevive con `deleted_at`, los subregistros quedan físicos, el "
    "detalle responde 404) con la reservación de un-abierto-por-persona LIBERADA "
    "(migración `2026_10_02_130000`: `open_case_key` NULL también en filas borradas) — "
    "suite 1108/3788 contra MySQL real, Pint/PHPStan 8/deptrac en verde y fumiga HTTP del "
    "ciclo de vida de 28 comprobaciones TODO OK | Arq. Backend |\n"
)

# ---------------------------------------------------------------------------
# Aplicación
# ---------------------------------------------------------------------------
patch(
    "Requisitos funcionales.md",
    [(RF_ITEM38_TAIL, RF_LIFECYCLE_ITEMS)],
)

patch(
    "Diseño de arquitectura.md",
    [(ARQ_GET_ROW_TAIL, ARQ_GET_ROW_TAIL + ARQ_LIFECYCLE_ROW)],
)

add_row_after(
    "Diseño de arquitectura.md",
    "| 1.31 | 2026-10-02 |",
    ARQ_CHANGELOG_132,
)

patch(
    "Modelo de datos.md",
    [
        (MD_OPENCASE_ANCHOR, MD_OPENCASE_NEW),
        (MD_MIGS_ANCHOR, MD_MIGS_NEW),
    ],
)

add_row_after(
    "Modelo de datos.md",
    "| 1.24 | 2026-10-02 |",
    MD_CHANGELOG_125,
)

patch(
    "04_Plan_de_desarrollo.md",
    [(PLAN_S52_TAIL, PLAN_S52_NEW)],
)

add_row_after(
    "04_Plan_de_desarrollo.md",
    "| 1.22 | 2026-10-02 |",
    PLAN_CHANGELOG_123,
)

print("\nTask 40: 7 parches de documentación aplicados")
