#!/usr/bin/env python3
"""Patch the four SGP documents for Task 46 / SGP-38 (user correction
of the DOCUMENTED design): worked_years goes INTEGER — SMALLINT
UNSIGNED instead of the DECIMAL(5,2) recorded by Task 45 — and the
user's formula now rounds to the NEAREST INTEGER (half up):

    years = ROUND(((YEAR(end) - YEAR(start)) * 12
                   + (MONTH(end) - MONTH(start))) / M)

where M = months_per_year of the pension regime of the case. The
field stays DERIVED (422 if the client sends it — never a silent
drop), still computed by the application service WHEN THE ROW IS
CREATED (nested rows and individual endpoint), still FROZEN at
creation — but it is now serialized as a JSON INTEGER number (6,
type: integer — unlike last_salary's decimal string). The user's
worked example: 2000-01-01 → 2005-12-31 with M = 12 → (5*12 + 11)/12
= 71/12 = 5.9167 → 6.

Phase 1 only: the docs keep the change as DOCUMENTADA — implementación
pendiente de la validación del usuario (the Task 42/45 pattern).

Anchored, asserted, idempotent patches (the Task 40/41/42/44/45 pattern).
"""

from pathlib import Path

ROOT = Path("/home/z/my-project/inass_siss/download")

REQUISITOS: list[tuple[str, str]] = [
    # Item head: the type adjustment enters the RF-EXP-003 item.
    (
        "Task 45/SGP-38, DOCUMENTADA — implementación pendiente de la validación "
        "del usuario): cada subregistro de servicio persiste y devuelve "
        "`worked_years` — DECIMAL(5,2), 2 posiciones exactas (RN-005: jamás "
        "float) —, calculado por el servicio de aplicación AL CREAR la fila con "
        "la fórmula del usuario: años = REDONDEAR(((AÑO(fecha_fin) - "
        "AÑO(fecha_ini)) × 12 + (MES(fecha_fin) - MES(fecha_ini))) / M; 2), donde M",
        "Task 45/SGP-38, TIPO AJUSTADO A ENTERO por el usuario en la Task 46, "
        "DOCUMENTADA — implementación pendiente de la validación del usuario): "
        "cada subregistro de servicio persiste y devuelve `worked_years` — "
        "ENTERO (Integer, ajuste de usuario de la Task 46: era DECIMAL(5,2); "
        "entero exacto, RN-005: jamás float) —, calculado por el servicio de "
        "aplicación AL CREAR la fila con la fórmula del usuario, redondeada al "
        "ENTERO más cercano (mitad hacia arriba): años = REDONDEAR(((AÑO(fecha_fin) "
        "- AÑO(fecha_ini)) × 12 + (MES(fecha_fin) - MES(fecha_ini))) / M), donde M",
    ),
    # Same-month edge: 0.00 becomes 0.
    (
        "un período dentro del mismo mes rinde 0.00, comportamiento literal de la fórmula",
        "un período dentro del mismo mes rinde 0, comportamiento literal de la fórmula",
    ),
    # Serialization: decimal string becomes a JSON integer number.
    (
        "Se serializa como string decimal de dos posiciones (`'5.92'`, igual que "
        "`last_salary`) en el 201 del alta",
        "Se serializa como NÚMERO ENTERO JSON (`6`, type: integer — a diferencia "
        "del string decimal de `last_salary`) en el 201 del alta",
    ),
    # The worked example: 5.92 becomes 6.
    (
        "= 71/12 = 5.9167 → `'5.92'`.",
        "= 71/12 = 5.9167 → `6` (redondeo al entero).",
    ),
]

MODELO: list[tuple[str, str]] = [
    # Column row: DECIMAL(5,2) becomes SMALLINT UNSIGNED.
    (
        "| worked_years | DECIMAL(5,2) | NO | — | Años de trabajo del vínculo "
        "(corrección de usuario, Task 45/SGP-38, DOCUMENTADA — implementación "
        "pendiente de la validación del usuario): calculados al crear la fila con "
        "la fórmula del usuario — REDONDEAR(((AÑO(end) - AÑO(start)) × 12 + "
        "(MES(end) - MES(start))) / M; 2), M = `months_per_year` del régimen del "
        "expediente — y congelados (sin edición de subregistros); 2 posiciones "
        "exactas (RN-005: jamás float); migración `2026_10_04_100000` |",
        "| worked_years | SMALLINT UNSIGNED | NO | — | Años de trabajo del "
        "vínculo (corrección de usuario, Task 45/SGP-38, TIPO ENTERO ajustado "
        "por el usuario en la Task 46, DOCUMENTADA — implementación pendiente de "
        "la validación del usuario): calculados al crear la fila con la fórmula "
        "del usuario redondeada al entero — REDONDEAR(((AÑO(end) - AÑO(start)) × "
        "12 + (MES(end) - MES(start))) / M), M = `months_per_year` del régimen "
        "del expediente — y congelados (sin edición de subregistros); entero "
        "exacto, jamás float (RN-005); migración `2026_10_04_100000` |",
    ),
    # Semantics paragraph: the whole calculation paragraph goes integer.
    (
        "Años de trabajo (Task 45, corrección de usuario, SGP-38, DOCUMENTADA — "
        "implementación pendiente de la validación del usuario): cada fila declara "
        "`worked_years` DECIMAL(5,2) — la doctrina RN-005 de «jamás float» "
        "aplicada a una medida de tiempo —, calculado por el servicio de "
        "aplicación AL CREAR el subregistro en los DOS puntos de entrada (filas "
        "anidadas del alta y endpoint propio) y jamás aceptado en el wire (422 si "
        "el cliente lo envía: es un valor derivado, no un dato del declarante). "
        "La fórmula del usuario, idéntica en SQL y en PHP: `ROUND(((YEAR(end_date) "
        "- YEAR(start_date)) * 12 + (MONTH(end_date) - MONTH(start_date))) / M, 2)` "
        "con `M = months_per_year` del régimen de pensión del expediente — AÑO/MES "
        "ignoran el día, `M > 0` está garantizado por el CHECK del catálogo y "
        "`end_date > start_date` (Task 37) garantiza un resultado definido y no "
        "negativo; el PHP replica el redondeo con aritmética ENTERA (centésimas = "
        "piso((200 × meses + M) / (2 × M)), luego el string `'X.YZ'` por "
        "composición de dígitos) para que el valor jamás pase por un float — "
        "2000-01-01 → 2005-12-31 con M = 12 rinde 71 meses → 71/12 → `'5.92'`, y "
        "un período dentro del mismo mes rinde `'0.00'` (comportamiento literal de "
        "la fórmula). El valor se CONGELA al crear (los subregistros no se "
        "editan: solo alta y baja) y viaja en el 201 del alta, el detalle del "
        "expediente y el schema OA como string de dos posiciones; el índice de "
        "expedientes no carga subregistros — sin cambio. La migración "
        "`2026_10_04_100000` añade la columna y recalcula las filas preexistentes "
        "con la MISMA fórmula en SQL (backfill de una sola pasada).",
        "Años de trabajo (Task 45, corrección de usuario, SGP-38, TIPO ENTERO "
        "ajustado por el usuario en la Task 46, DOCUMENTADA — implementación "
        "pendiente de la validación del usuario): cada fila declara `worked_years` "
        "SMALLINT UNSIGNED — entero exacto: la doctrina RN-005 de «jamás float» "
        "aplicada a una medida de tiempo —, calculado por el servicio de "
        "aplicación AL CREAR el subregistro en los DOS puntos de entrada (filas "
        "anidadas del alta y endpoint propio) y jamás aceptado en el wire (422 si "
        "el cliente lo envía: es un valor derivado, no un dato del declarante). "
        "La fórmula del usuario, idéntica en SQL y en PHP, redondeada al ENTERO "
        "más cercano con mitad hacia arriba: `ROUND(((YEAR(end_date) - "
        "YEAR(start_date)) * 12 + (MONTH(end_date) - MONTH(start_date))) / M)` con "
        "`M = months_per_year` del régimen de pensión del expediente — AÑO/MES "
        "ignoran el día, `M > 0` está garantizado por el CHECK del catálogo y "
        "`end_date > start_date` (Task 37) garantiza un resultado definido y no "
        "negativo; el PHP replica el redondeo con aritmética ENTERA (años = "
        "piso((2 × meses + M) / (2 × M)), equivalente exacto del REDONDEAR de "
        "meses/M al entero con mitad hacia arriba) para que el valor jamás pase "
        "por un float — 2000-01-01 → 2005-12-31 con M = 12 rinde 71 meses → "
        "71/12 = 5.9167 → `6`, un período dentro del mismo mes rinde `0` "
        "(comportamiento literal de la fórmula) y la mitad exacta sube (6 meses / "
        "12 = 0.5 → `1`). El valor se CONGELA al crear (los subregistros no se "
        "editan: solo alta y baja) y viaja en el 201 del alta, el detalle del "
        "expediente y el schema OA como NÚMERO ENTERO (type: integer, a "
        "diferencia del string decimal de `last_salary`); el índice de "
        "expedientes no carga subregistros — sin cambio. La migración "
        "`2026_10_04_100000` añade la columna y recalcula las filas preexistentes "
        "con la MISMA fórmula en SQL (backfill de una sola pasada, ROUND al "
        "entero).",
    ),
    # Migration list parenthetical: the type adjustment is named.
    (
        "y `2026_10_04_100000_add_worked_years_to_service_records_table` (Task "
        "45/SGP-38, DOCUMENTADA — implementación pendiente de la validación del "
        "usuario: años de trabajo del vínculo, calculados al alta):",
        "y `2026_10_04_100000_add_worked_years_to_service_records_table` (Task "
        "45/SGP-38, TIPO ENTERO ajustado por el usuario en la Task 46, DOCUMENTADA "
        "— implementación pendiente de la validación del usuario: años de trabajo "
        "del vínculo, calculados al alta):",
    ),
    # Changelog 1.29 appended after the 1.28 row.
    (
        "de 5.7 y backfill SQL de las filas preexistentes en la migración "
        "`2026_10_04_100000` — pendiente de la validación del usuario para "
        "implementar | Arq. Backend |",
        "de 5.7 y backfill SQL de las filas preexistentes en la migración "
        "`2026_10_04_100000` — pendiente de la validación del usuario para "
        "implementar | Arq. Backend |\n"
        "| 1.29 | 2026-10-04 | Ajuste de usuario (Task 46, SGP-38; DOCUMENTADA — "
        "implementación pendiente de la validación del usuario): `worked_years` "
        "pasa a ENTERO — SMALLINT UNSIGNED en lugar del DECIMAL(5,2) de 1.28 — y "
        "la fórmula del usuario redondea al ENTERO más cercano (mitad hacia "
        "arriba; ejemplo del usuario: 71/12 = 5.9167 → `6`): fila de columna, "
        "párrafo de cálculo (aritmética entera en PHP: años = piso((2 × meses + "
        "M) / (2 × M))) y lista de migraciones de 5.7 ajustadas; serialización "
        "como NÚMERO ENTERO JSON (type: integer) en 201/detalle/schema OA — "
        "pendiente de la validación del usuario para implementar | Arq. Backend |",
    ),
]

ARQUITECTURA: list[tuple[str, str]] = [
    # Endpoints table: the Task 45 note goes integer.
    (
        "desde la Task 45 (SGP-38, corrección de usuario, DOCUMENTADA — "
        "implementación pendiente de la validación del usuario) cada fila de "
        "servicio calcula al alta sus AÑOS DE TRABAJO `worked_years` DECIMAL(5,2) "
        "con la fórmula del usuario (REDONDEAR(meses de diferencia / M; 2), M = "
        "`months_per_year` del régimen del expediente) en el servicio de "
        "aplicación — campo DERIVADO: 422 si el cliente lo envía, string `'5.92'` "
        "en el 201 y el detalle, valor CONGELADO (sin PATCH); PATCH de filas "
        "diferido a S6 |",
        "desde la Task 45 (SGP-38, corrección de usuario con el TIPO ENTERO "
        "ajustado por el usuario en la Task 46, DOCUMENTADA — implementación "
        "pendiente de la validación del usuario) cada fila de servicio calcula al "
        "alta sus AÑOS DE TRABAJO `worked_years` ENTERO (SMALLINT UNSIGNED) con "
        "la fórmula del usuario redondeada al entero (REDONDEAR(meses de "
        "diferencia / M), M = `months_per_year` del régimen del expediente) en el "
        "servicio de aplicación — campo DERIVADO: 422 si el cliente lo envía, "
        "número entero `6` (type: integer) en el 201 y el detalle, valor "
        "CONGELADO (sin PATCH); PATCH de filas diferido a S6 |",
    ),
    # Changelog 1.37 appended after the 1.36 row.
    (
        "fila de endpoints de subregistros ampliada y changelog de Modelo de "
        "datos (1.28) alineado | Arq. Backend |",
        "fila de endpoints de subregistros ampliada y changelog de Modelo de "
        "datos (1.28) alineado | Arq. Backend |\n"
        "| 1.37 | 2026-10-04 | Ajuste de usuario (Task 46, SGP-38; DOCUMENTADA — "
        "implementación pendiente de la validación del usuario): `worked_years` "
        "pasa a ENTERO — SMALLINT UNSIGNED en lugar del DECIMAL(5,2) de 1.36 —, "
        "la fórmula del usuario redondea al ENTERO más cercano (mitad hacia "
        "arriba; ejemplo: 71/12 = 5.9167 → `6`) y la serialización pasa del "
        "string de dos posiciones al NÚMERO ENTERO JSON (type: integer) por "
        "`ServiceRecordResource` en el 201 y el detalle (spec OpenAPI 1.6.0 al "
        "implementar); fila de endpoints de subregistros y changelog de Modelo "
        "de datos (1.29) alineados | Arq. Backend |",
    ),
]

PLAN: list[tuple[str, str]] = [
    # The Sprint 5 close item: rewritten for the integer type.
    (
        "- [ ] **Corrección de usuario (Task 45, SGP-38) — DOCUMENTADA 2026-10-04, "
        "implementación pendiente de la validación del usuario**: los subregistros "
        "de servicio ganan `worked_years` DECIMAL(5,2) — años de trabajo del "
        "vínculo, calculados al crear la fila (alta anidada y endpoint propio) con "
        "la fórmula del usuario `REDONDEAR(((AÑO(end) - AÑO(start)) × 12 + "
        "(MES(end) - MES(start))) / M; 2)` donde M = `months_per_year` del "
        "régimen del expediente (ejemplo del usuario: 2000-01-01 → 2005-12-31 con "
        "M = 12 → 71/12 → `'5.92'`) —, campo DERIVADO (422 si el cliente lo "
        "envía, jamás un descarte silencioso) serializado como string de dos "
        "posiciones en el 201, el detalle y el schema OA (spec OpenAPI 1.6.0 al "
        "implementar), CONGELADO al alta (sin edición de subregistros) y con "
        "backfill SQL de las filas preexistentes en la migración "
        "`2026_10_04_100000`.",
        "- [ ] **Corrección de usuario (Task 45, SGP-38; tipo ajustado a ENTERO "
        "por el usuario en la Task 46) — DOCUMENTADA 2026-10-04, implementación "
        "pendiente de la validación del usuario**: los subregistros de servicio "
        "ganan `worked_years` ENTERO (Integer; SMALLINT UNSIGNED — ajuste de "
        "usuario de la Task 46: era DECIMAL(5,2)) — años de trabajo del vínculo, "
        "calculados al crear la fila (alta anidada y endpoint propio) con la "
        "fórmula del usuario redondeada al ENTERO más cercano (mitad hacia "
        "arriba) `REDONDEAR(((AÑO(end) - AÑO(start)) × 12 + (MES(end) - "
        "MES(start))) / M)` donde M = `months_per_year` del régimen del expediente "
        "(ejemplo del usuario: 2000-01-01 → 2005-12-31 con M = 12 → 71/12 = "
        "5.9167 → `6`) —, campo DERIVADO (422 si el cliente lo envía, jamás un "
        "descarte silencioso) serializado como NÚMERO ENTERO JSON (type: integer) "
        "en el 201, el detalle y el schema OA (spec OpenAPI 1.6.0 al implementar), "
        "CONGELADO al alta (sin edición de subregistros) y con backfill SQL de "
        "las filas preexistentes (ROUND al entero) en la migración "
        "`2026_10_04_100000`.",
    ),
    # Changelog 1.28 appended after the 1.27 row.
    (
        "ítem DOCUMENTADA añadido al cierre del Sprint 5 y changelogs de Modelo "
        "de datos (1.28) y Arquitectura (1.36) alineados | Arq. Backend |",
        "ítem DOCUMENTADA añadido al cierre del Sprint 5 y changelogs de Modelo "
        "de datos (1.28) y Arquitectura (1.36) alineados | Arq. Backend |\n"
        "| 1.28 | 2026-10-04 | Ajuste de usuario (Task 46, SGP-38; DOCUMENTADA — "
        "implementación pendiente de la validación del usuario): `worked_years` "
        "pasa a ENTERO — SMALLINT UNSIGNED en lugar del DECIMAL(5,2) de 1.27 —, "
        "la fórmula redondea al ENTERO más cercano (mitad hacia arriba; 71/12 = "
        "5.9167 → `6`) y la serialización es NÚMERO ENTERO JSON (type: integer); "
        "ítem del cierre del Sprint 5 reescrito y changelogs de Modelo de datos "
        "(1.29) y Arquitectura (1.37) alineados | Arq. Backend |",
    ),
]

FILES: list[tuple[str, list[tuple[str, str]], str]] = [
    ("Requisitos funcionales.md", REQUISITOS, "TIPO AJUSTADO A ENTERO"),
    ("Modelo de datos.md", MODELO, "1.29 | 2026-10-04"),
    ("Diseño de arquitectura.md", ARQUITECTURA, "1.37 | 2026-10-04"),
    ("04_Plan_de_desarrollo.md", PLAN, "1.28 | 2026-10-04"),
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
