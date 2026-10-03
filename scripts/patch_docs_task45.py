#!/usr/bin/env python3
"""Patch the four SGP documents for Task 45 / SGP-38 (user correction,
DOCUMENTED — implementation pending the user validation): every service
record row gains worked_years DECIMAL(5,2) — years of work of the
link — computed by the application service WHEN THE ROW IS CREATED,
with the user's literal formula:

    years = ROUND(((YEAR(end) - YEAR(start)) * 12
                   + (MONTH(end) - MONTH(start))) / M, 2)

where M = months_per_year of the pension regime of the case. The
field is DERIVED: it never travels in the input payload (422 if the
client sends it — never a silent drop), it is serialized as a
two-decimal string ('5.92', same as last_salary) by
ServiceRecordResource and it is frozen at creation (subrecords have
no edit surface). The user's worked example: 2000-01-01 → 2005-12-31
with M = 12 → (5*12 + 11)/12 = 71/12 = 5.9167 → '5.92'.

Phase 1 only: the docs mark the change as DOCUMENTADA — implementación
pendiente de la validación del usuario (the Task 42 pattern).

Anchored, asserted, idempotent patches (the Task 40/41/42/44 pattern).
"""

from pathlib import Path

ROOT = Path("/home/z/my-project/inass_siss/download")

REQUISITOS: list[tuple[str, str]] = [
    # RF-EXP-003: new unchecked item after the Task 37 overlap rule.
    (
        "el objeto `warnings` queda solo con los años salariales interiores ausentes (RF-EXP-002).",
        "el objeto `warnings` queda solo con los años salariales interiores ausentes (RF-EXP-002).\n"
        "- [ ] AÑOS DE TRABAJO por fila (corrección de usuario, Task 45/SGP-38, DOCUMENTADA — "
        "implementación pendiente de la validación del usuario): cada subregistro de servicio "
        "persiste y devuelve `worked_years` — DECIMAL(5,2), 2 posiciones exactas (RN-005: jamás "
        "float) —, calculado por el servicio de aplicación AL CREAR la fila con la fórmula del "
        "usuario: años = REDONDEAR(((AÑO(fecha_fin) - AÑO(fecha_ini)) × 12 + (MES(fecha_fin) - "
        "MES(fecha_ini))) / M; 2), donde M = `months_per_year` del régimen de pensión del "
        "expediente (AÑO/MES ignoran el día; M > 0 garantizado por el CHECK del catálogo; "
        "`end_date > start_date` estricto — Task 37 — garantiza un resultado definido y no "
        "negativo; un período dentro del mismo mes rinde 0.00, comportamiento literal de la "
        "fórmula). El campo es DERIVADO y NO se acepta en el payload de entrada: 422 si el "
        "cliente lo envía, tanto en el endpoint propio como en las filas anidadas del alta "
        "(jamás un descarte silencioso — la lección de Task 33). Se serializa como string "
        "decimal de dos posiciones (`'5.92'`, igual que `last_salary`) en el 201 del alta, el "
        "detalle del expediente y el schema OA (spec OpenAPI 1.6.0); sin edición de subregistros "
        "(solo alta y baja), el valor queda CONGELADO al crear la fila; el listado de "
        "expedientes no carga subregistros — sin cambio. Ejemplo numérico del usuario: "
        "2000-01-01 → 2005-12-31 con M = 12 → (5 × 12 + 11) / 12 = 71/12 = 5.9167 → `'5.92'`.",
    ),
]

MODELO: list[tuple[str, str]] = [
    # Column row after declaration_form.
    (
        "| declaration_form | VARCHAR(20) | NO | DEFAULT 'Documental' | Forma de declaración del "
        "vínculo: Documental (respaldo documental) o Testifical (testimonio); CHECK "
        "Documental\\|Testifical (corrección de usuario, Task 32; columna inglesa desde Task 36) |",
        "| declaration_form | VARCHAR(20) | NO | DEFAULT 'Documental' | Forma de declaración del "
        "vínculo: Documental (respaldo documental) o Testifical (testimonio); CHECK "
        "Documental\\|Testifical (corrección de usuario, Task 32; columna inglesa desde Task 36) |\n"
        "| worked_years | DECIMAL(5,2) | NO | — | Años de trabajo del vínculo (corrección de "
        "usuario, Task 45/SGP-38, DOCUMENTADA — implementación pendiente de la validación del "
        "usuario): calculados al crear la fila con la fórmula del usuario — REDONDEAR(((AÑO(end) "
        "- AÑO(start)) × 12 + (MES(end) - MES(start))) / M; 2), M = `months_per_year` del régimen "
        "del expediente — y congelados (sin edición de subregistros); 2 posiciones exactas "
        "(RN-005: jamás float); migración `2026_10_04_100000` |",
    ),
    # Semantics paragraph after the ServicePeriods overlap paragraph.
    (
        "MySQL no expresa el solapamiento como constraint.",
        "MySQL no expresa el solapamiento como constraint.\n"
        "\n"
        "Años de trabajo (Task 45, corrección de usuario, SGP-38, DOCUMENTADA — implementación "
        "pendiente de la validación del usuario): cada fila declara `worked_years` DECIMAL(5,2) "
        "— la doctrina RN-005 de «jamás float» aplicada a una medida de tiempo —, calculado por "
        "el servicio de aplicación AL CREAR el subregistro en los DOS puntos de entrada (filas "
        "anidadas del alta y endpoint propio) y jamás aceptado en el wire (422 si el cliente lo "
        "envía: es un valor derivado, no un dato del declarante). La fórmula del usuario, "
        "idéntica en SQL y en PHP: `ROUND(((YEAR(end_date) - YEAR(start_date)) * 12 + "
        "(MONTH(end_date) - MONTH(start_date))) / M, 2)` con `M = months_per_year` del régimen "
        "de pensión del expediente — AÑO/MES ignoran el día, `M > 0` está garantizado por el "
        "CHECK del catálogo y `end_date > start_date` (Task 37) garantiza un resultado definido "
        "y no negativo; el PHP replica el redondeo con aritmética ENTERA (centésimas = "
        "piso((200 × meses + M) / (2 × M)), luego el string `'X.YZ'` por composición de dígitos) "
        "para que el valor jamás pase por un float — 2000-01-01 → 2005-12-31 con M = 12 rinde "
        "71 meses → 71/12 → `'5.92'`, y un período dentro del mismo mes rinde `'0.00'` "
        "(comportamiento literal de la fórmula). El valor se CONGELA al crear (los subregistros "
        "no se editan: solo alta y baja) y viaja en el 201 del alta, el detalle del expediente y "
        "el schema OA como string de dos posiciones; el índice de expedientes no carga "
        "subregistros — sin cambio. La migración `2026_10_04_100000` añade la columna y "
        "recalcula las filas preexistentes con la MISMA fórmula en SQL (backfill de una sola "
        "pasada).",
    ),
    # Migration list: the new migration joins the 5.7 inventory.
    (
        "`2026_10_03_100300_add_applied_percent_to_income_concept_records_table` (Task 42/SGP-36: "
        "eliminación del catálogo de tipos de pago, forma de pago del tipo de agencia, grupo de "
        "domicilio y cobro del promovente con cuenta condicional, y porciento a aplicar del "
        "concepto de ingreso):",
        "`2026_10_03_100300_add_applied_percent_to_income_concept_records_table` (Task 42/SGP-36: "
        "eliminación del catálogo de tipos de pago, forma de pago del tipo de agencia, grupo de "
        "domicilio y cobro del promovente con cuenta condicional, y porciento a aplicar del "
        "concepto de ingreso) y `2026_10_04_100000_add_worked_years_to_service_records_table` "
        "(Task 45/SGP-38, DOCUMENTADA — implementación pendiente de la validación del usuario: "
        "años de trabajo del vínculo, calculados al alta):",
    ),
    # Changelog 1.28 appended after the 1.27 row.
    (
        "spec OpenAPI 1.5.0, suite 1134/3966 contra MySQL real, Pint/PHPStan 8/deptrac en verde "
        "y fumiga de expedientes ampliada a 73 comprobaciones TODO OK | Arq. Backend |",
        "spec OpenAPI 1.5.0, suite 1134/3966 contra MySQL real, Pint/PHPStan 8/deptrac en verde "
        "y fumiga de expedientes ampliada a 73 comprobaciones TODO OK | Arq. Backend |\n"
        "| 1.28 | 2026-10-04 | Corrección de usuario (Task 45, SGP-38; DOCUMENTADA — "
        "implementación pendiente de la validación del usuario): `service_records` gana "
        "`worked_years` DECIMAL(5,2) — años de trabajo del vínculo calculados al crear la fila "
        "con la fórmula del usuario (REDONDEAR(((AÑO(end) - AÑO(start)) × 12 + (MES(end) - "
        "MES(start))) / M; 2) con M = `months_per_year` del régimen del expediente; ejemplo del "
        "usuario: 2000-01-01 → 2005-12-31 con M = 12 → 71/12 → `'5.92'`), campo DERIVADO "
        "rechazado en el wire (422 si el cliente lo envía), serializado como string de dos "
        "posiciones en 201/detalle/schema OA (spec 1.6.0 al implementar) y CONGELADO al alta "
        "(sin edición de subregistros); fila de columna, párrafo de cálculo, lista de migraciones "
        "de 5.7 y backfill SQL de las filas preexistentes en la migración `2026_10_04_100000` — "
        "pendiente de la validación del usuario para implementar | Arq. Backend |",
    ),
]

ARQUITECTURA: list[tuple[str, str]] = [
    # Endpoints table: the subrecords row gains the Task 45 note.
    (
        "las bajas son físicas y auditadas con valores previos; PATCH de filas diferido a S6 |",
        "las bajas son físicas y auditadas con valores previos; desde la Task 45 (SGP-38, "
        "corrección de usuario, DOCUMENTADA — implementación pendiente de la validación del "
        "usuario) cada fila de servicio calcula al alta sus AÑOS DE TRABAJO `worked_years` "
        "DECIMAL(5,2) con la fórmula del usuario (REDONDEAR(meses de diferencia / M; 2), M = "
        "`months_per_year` del régimen del expediente) en el servicio de aplicación — campo "
        "DERIVADO: 422 si el cliente lo envía, string `'5.92'` en el 201 y el detalle, valor "
        "CONGELADO (sin PATCH); PATCH de filas diferido a S6 |",
    ),
    # Changelog 1.36 appended after the 1.35 row.
    (
        "spec OpenAPI 1.5.0 con la descripción del índice anclada por ApiDocsTest — suite "
        "1134/3966 contra MySQL real, Pint/PHPStan 8/deptrac en verde y fumiga de expedientes "
        "ampliada a 73 comprobaciones TODO OK | Arq. Backend |",
        "spec OpenAPI 1.5.0 con la descripción del índice anclada por ApiDocsTest — suite "
        "1134/3966 contra MySQL real, Pint/PHPStan 8/deptrac en verde y fumiga de expedientes "
        "ampliada a 73 comprobaciones TODO OK | Arq. Backend |\n"
        "| 1.36 | 2026-10-04 | Corrección de usuario (Task 45, SGP-38; DOCUMENTADA — "
        "implementación pendiente de la validación del usuario): cada subregistro de servicio "
        "gana `worked_years` DECIMAL(5,2) — años de trabajo calculados en el servicio de "
        "aplicación al crear la fila (payload anidado y endpoint propio) con la fórmula del "
        "usuario: REDONDEAR(((AÑO(end) - AÑO(start)) × 12 + (MES(end) - MES(start))) / M; 2) "
        "con M = `months_per_year` del régimen del expediente, replicada en PHP con aritmética "
        "ENTERA (jamás float, RN-005) —; campo DERIVADO rechazado en el wire con 422 en ambos "
        "puntos de entrada (jamás un descarte silencioso), serializado como string de dos "
        "posiciones (`'5.92'`) por `ServiceRecordResource` en el 201 y el detalle (spec OpenAPI "
        "1.6.0 al implementar) y CONGELADO al alta (los subregistros no se editan); fila de "
        "endpoints de subregistros ampliada y changelog de Modelo de datos (1.28) alineado | "
        "Arq. Backend |",
    ),
]

PLAN: list[tuple[str, str]] = [
    # New documentation item at the close of Sprint 5.
    (
        "**Sprint 6 — Máquina de estados, historial y búsqueda (S6.1-S6.5)**",
        "- [ ] **Corrección de usuario (Task 45, SGP-38) — DOCUMENTADA 2026-10-04, implementación "
        "pendiente de la validación del usuario**: los subregistros de servicio ganan "
        "`worked_years` DECIMAL(5,2) — años de trabajo del vínculo, calculados al crear la fila "
        "(alta anidada y endpoint propio) con la fórmula del usuario `REDONDEAR(((AÑO(end) - "
        "AÑO(start)) × 12 + (MES(end) - MES(start))) / M; 2)` donde M = `months_per_year` del "
        "régimen del expediente (ejemplo del usuario: 2000-01-01 → 2005-12-31 con M = 12 → "
        "71/12 → `'5.92'`) —, campo DERIVADO (422 si el cliente lo envía, jamás un descarte "
        "silencioso) serializado como string de dos posiciones en el 201, el detalle y el "
        "schema OA (spec OpenAPI 1.6.0 al implementar), CONGELADO al alta (sin edición de "
        "subregistros) y con backfill SQL de las filas preexistentes en la migración "
        "`2026_10_04_100000`.\n"
        "\n"
        "**Sprint 6 — Máquina de estados, historial y búsqueda (S6.1-S6.5)**",
    ),
    # Changelog 1.27 appended after the 1.26 row.
    (
        "spec OpenAPI 1.5.0 anclada por ApiDocsTest, suite 1134/3966 contra MySQL real, "
        "Pint/PHPStan 8/deptrac en verde y fumiga de expedientes ampliada a 73 comprobaciones "
        "TODO OK | Arq. Backend |",
        "spec OpenAPI 1.5.0 anclada por ApiDocsTest, suite 1134/3966 contra MySQL real, "
        "Pint/PHPStan 8/deptrac en verde y fumiga de expedientes ampliada a 73 comprobaciones "
        "TODO OK | Arq. Backend |\n"
        "| 1.27 | 2026-10-04 | Corrección de usuario (Task 45, SGP-38; DOCUMENTADA — "
        "implementación pendiente de la validación del usuario): los subregistros de servicio "
        "ganan `worked_years` DECIMAL(5,2) — años de trabajo del vínculo calculados al alta "
        "con la fórmula del usuario (meses de diferencia entre start y end divididos por el M "
        "del régimen, redondeados a 2 posiciones; ejemplo del usuario: 71/12 → `'5.92'`) —, "
        "rechazado en el wire (422, campo derivado), serializado `'5.92'` en 201/detalle y "
        "CONGELADO al alta; ítem DOCUMENTADA añadido al cierre del Sprint 5 y changelogs de "
        "Modelo de datos (1.28) y Arquitectura (1.36) alineados | Arq. Backend |",
    ),
]

FILES: list[tuple[str, list[tuple[str, str]], str]] = [
    ("Requisitos funcionales.md", REQUISITOS, "Task 45/SGP-38"),
    ("Modelo de datos.md", MODELO, "1.28 | 2026-10-04"),
    ("Diseño de arquitectura.md", ARQUITECTURA, "1.36 | 2026-10-04"),
    ("04_Plan_de_desarrollo.md", PLAN, "1.27 | 2026-10-04"),
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
