#!/usr/bin/env python3
"""Patch the four SGP documents for Task 44 / SGP-37 (user correction,
IMPLEMENTED): the GET /pension-cases LISTING answers the promovente
residence + collection projections on every row — residence_province
and residence_municipality as {id, code, name}, the collection agency
type carrying its payment_form and the FULL agency shape — the same
projections the detail surface already answers.

No schema change: the projections already lived in PensionCaseResource
under whenLoaded and findDetailed already eager-loaded them since the
Task 43; the gap was only the listing's search() — the docs now record
the closure.

Anchored, asserted, idempotent patches (the Task 40/41/42 pattern).
"""

from pathlib import Path

ROOT = Path("/home/z/my-project/download")

SUITE = "suite 1134/3966 contra MySQL real, Pint/PHPStan 8/deptrac en verde"

REQUISITOS: list[tuple[str, str]] = [
    # RF-EXP-011: new [x] item after the Task 41 territorial scope one.
    (
        "angulan DENTRO de la oficina del actor, jamás a través de ella.",
        "angulan DENTRO de la oficina del actor, jamás a través de ella.\n"
        "- [x] DATOS DE RESIDENCIA Y COBRO EN CADA FILA (corrección de usuario, Task 44/SGP-37, "
        "IMPLEMENTADA): el listado devuelve la provincia y el municipio de residencia del promovente "
        "como proyecciones {id, code, name} y los DATOS de la agencia de cobro — el tipo de agencia con "
        "su `payment_form` y la agencia COMPLETA (código, nombre, tipo, provincia y municipio) —, las "
        "mismas proyecciones que el detalle del expediente: consumir el directorio no exige una segunda "
        "consulta por fila para resolver la geografía o el punto de cobro (spec OpenAPI 1.5.0).",
    ),
]

MODELO: list[tuple[str, str]] = [
    # 5.7 semantics: the listing now serves the group projections.
    (
        "de la esfera inmutable de la persona —, con las mismas convenciones de autoría y bitácora del agregado.",
        "de la esfera inmutable de la persona —, y desde la Task 44 (corrección de usuario, SGP-37) el "
        "LISTADO sirve el grupo con sus proyecciones completas (cargas anticipadas en `search` del "
        "repositorio: provincia y municipio de residencia {id, code, name}, tipo de agencia con "
        "`payment_form` y agencia con su geografía — mismas proyecciones del detalle, sin segunda "
        "consulta por fila), con las mismas convenciones de autoría y bitácora del agregado.",
    ),
    # Changelog 1.27 appended after the 1.26 row.
    (
        "suite 1133/3945 contra MySQL real, Pint/PHPStan 8/deptrac en verde y fumigas TODO OK | Arq. Backend |",
        "suite 1133/3945 contra MySQL real, Pint/PHPStan 8/deptrac en verde y fumigas TODO OK | Arq. Backend |\n"
        "| 1.27 | 2026-10-03 | Corrección de usuario (Task 44, SGP-37; IMPLEMENTADA): el LISTADO de "
        "expedientes sirve el grupo de domicilio y cobro del promovente con sus proyecciones completas — "
        "`search` del repositorio Eloquent gana las cargas anticipadas de `residenceProvince`, "
        "`residenceMunicipality`, `collectionAgencyType` y `collectionAgency` (con su geografía y tipo) "
        "que `PensionCaseResource` ya proyectaba bajo `whenLoaded` — de modo que cada fila del GET "
        "`/pension-cases` responde provincia y municipio de residencia {id, code, name}, tipo de agencia "
        "con `payment_form` y agencia COMPLETA (código, nombre, tipo, provincia y municipio): las mismas "
        "proyecciones del detalle, sin segunda consulta por fila; SIN cambio de esquema (la semántica 5.7 "
        "ya prometía el listado — la brecha estaba en la carga); spec OpenAPI 1.5.0, " + SUITE + " y "
        "fumiga de expedientes ampliada a 73 comprobaciones TODO OK | Arq. Backend |",
    ),
]

ARQUITECTURA: list[tuple[str, str]] = [
    # Endpoints table: the GET row gains the Task 44 projections note.
    (
        "cada fila viaja con la proyección COMPLETA del promovente (regla de usuario 3, `PersonResource` reutilizado)",
        "cada fila viaja con la proyección COMPLETA del promovente (regla de usuario 3, `PersonResource` "
        "reutilizado) y, desde la Task 44 (SGP-37, corrección de usuario, IMPLEMENTADA), con los DATOS de "
        "residencia y cobro del promovente — provincia y municipio de residencia como {id, code, name} y "
        "la agencia de cobro COMPLETA con su tipo (`payment_form`) y geografía, las mismas proyecciones "
        "del detalle servidas por las cargas anticipadas del listado del repositorio, sin segunda "
        "consulta por fila",
    ),
    # Changelog 1.35 appended after the 1.34 row.
    (
        "fumigas de expedientes/ciclo de vida ampliadas TODO OK | Arq. Backend |",
        "fumigas de expedientes/ciclo de vida ampliadas TODO OK | Arq. Backend |\n"
        "| 1.35 | 2026-10-03 | Corrección de usuario (Task 44, SGP-37; IMPLEMENTADA): el GET "
        "`/api/v1/pension-cases` devuelve en cada fila los DATOS de provincia y municipio de residencia "
        "del promovente y de la agencia de cobro — `residence_province`/`residence_municipality` como "
        "{id, code, name}, el tipo de agencia de cobro con su `payment_form` y la agencia COMPLETA "
        "(código, nombre, tipo, provincia y municipio, `AgencyResource` reutilizado) — las mismas "
        "proyecciones que el detalle (`findDetailed`), logradas con las cargas anticipadas en `search` "
        "del repositorio (sin N+1: la página resuelve sus filas en las consultas anticipadas); fila de "
        "endpoints del GET ampliada, spec OpenAPI 1.5.0 con la descripción del índice anclada por "
        "ApiDocsTest — " + SUITE + " y fumiga de expedientes ampliada a 73 comprobaciones TODO OK | "
        "Arq. Backend |",
    ),
]

PLAN: list[tuple[str, str]] = [
    # New implementation item at the close of Sprint 5.
    (
        "**Sprint 6 — Máquina de estados, historial y búsqueda (S6.1-S6.5)**",
        "- [x] **Corrección de usuario (Task 44, SGP-37) — IMPLEMENTADA 2026-10-03** (spec OpenAPI "
        "1.5.0, " + SUITE + ", fumiga de expedientes de 73 comprobaciones TODO OK): el GET "
        "`/pension-cases` devuelve en cada fila los DATOS de provincia y municipio de residencia y de "
        "la agencia de cobro — `search` del repositorio Eloquent gana las cargas anticipadas de "
        "`residenceProvince`, `residenceMunicipality`, `collectionAgencyType` y "
        "`collectionAgency.province/municipality/agencyType` (la proyección ya vivía en "
        "`PensionCaseResource` bajo `whenLoaded` y el detalle las cargaba desde la Task 43: la brecha "
        "estaba solo en el listado), test de feature del listado anclando fila a fila las proyecciones "
        "(provincia, municipio, tipo con `payment_form`, agencia completa con su geografía) y fumiga "
        "ampliada sobre la BD sgp.\n"
        "\n"
        "**Sprint 6 — Máquina de estados, historial y búsqueda (S6.1-S6.5)**",
    ),
    # Changelog 1.26 appended after the 1.25 row.
    (
        "fumigas TODO OK) | Arq. Backend |",
        "fumigas TODO OK) | Arq. Backend |\n"
        "| 1.26 | 2026-10-03 | Corrección de usuario (Task 44, SGP-37; IMPLEMENTADA): el listado de "
        "expedientes devuelve en cada fila los DATOS de provincia y municipio de residencia y de la "
        "agencia de cobro — cargas anticipadas de las cuatro relaciones del grupo de domicilio y cobro "
        "en `search` del repositorio (la proyección ya vivía en `PensionCaseResource` condicionada a "
        "`whenLoaded`; el detalle las cargaba desde la Task 43, el listado no), ítem de implementación "
        "añadido al cierre del Sprint 5 y changelogs de Modelo de datos (1.27) y Arquitectura (1.35) "
        "alineados — spec OpenAPI 1.5.0 anclada por ApiDocsTest, " + SUITE + " y fumiga de expedientes "
        "ampliada a 73 comprobaciones TODO OK | Arq. Backend |",
    ),
]

FILES: list[tuple[str, list[tuple[str, str]], str]] = [
    ("Requisitos funcionales.md", REQUISITOS, "Task 44/SGP-37"),
    ("Modelo de datos.md", MODELO, "1.27 | 2026-10-03"),
    ("Diseño de arquitectura.md", ARQUITECTURA, "1.35 | 2026-10-03"),
    ("04_Plan_de_desarrollo.md", PLAN, "1.26 | 2026-10-03"),
]


def main() -> None:
    for name, patches, marker in FILES:
        path = ROOT / name
        text = path.read_text(encoding="utf-8")

        if marker in text:
            print(f"SKIP {name}: marker '{marker}' already present (idempotent re-run)")
            continue

        applied = 0
        for old, new in patches:
            count = text.count(old)
            assert count == 1, (
                f"{name}: anchor is not unique (count={count}): {old[:80]!r}"
            )
            text = text.replace(old, new, 1)
            applied += 1

        path.write_text(text, encoding="utf-8")
        print(f"OK   {name}: {applied} patch(es) applied")


if __name__ == "__main__":
    main()
