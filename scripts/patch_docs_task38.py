#!/usr/bin/env python3
"""Task 38 (SGP-32) — parches de documentación.

Corrección de usuario: (A) el expediente gana la fecha de desvinculación del
promovente (termination_date, DATE opcional); (B) el régimen de jubilación gana
un sector entero opcional devuelto por TODOS los endpoints del catálogo;
(C) el tipo de pensión gana persona fallecida (deceased_person, booleano con
default false devuelto por TODOS los endpoints); (D) FIX: el GET del listado
de entidades devuelve los DATOS del director general y el económico como
proyecciones completas de Persona.

Cuatro archivos, parches con ancla única y aserción de unicidad (patrón de
las Tasks 34-37). Suite 1069/3635, fumiga de expedientes con 54
comprobaciones TODO OK.
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


# ---------------------------------------------------------------------------
# 1) Requisitos funcionales.md
# ---------------------------------------------------------------------------
RF_T37_ITEM_TAIL = (
    "el alta los recibe (migración `2026_10_02_110000`) y el 201, el detalle y "
    "el listado los devuelven.\n"
)

RF_T38_ITEM = RF_T37_ITEM_TAIL + (
    "- [x] Fecha de desvinculación del promovente (corrección de usuario, Task 38/SGP-32): campo "
    "`termination_date` DATE NULL — opcional en el wire con la regla de forma Y-m-d única (422 con "
    "formato inválido; sin sonda semántica porque la corrección la declara opcional a secas; la "
    "omisión, null y '' persisten NULL) —: el alta la recibe (migración `2026_10_02_120000`) y el "
    "201, el detalle y el listado la devuelven.\n"
)

RF_CAT_T31_TAIL = (
    "de modo que las dieciséis superficies uniformes responden con la clave natural de integración.\n"
)

RF_CAT_T38_ITEM = RF_CAT_T31_TAIL + (
    "- [x] Columnas propias de los catálogos de pensión (corrección de usuario, Task 38/SGP-32): el "
    "régimen de jubilación gana `sector` INT NULL — opcional, devuelto por TODOS los endpoints del "
    "recurso genérico (201 del alta, detalle, listado y PATCH; PATCH sin el campo lo deja intacto, "
    "422 con valor no entero; migración `2026_10_02_120100`) — y el tipo de pensión gana persona "
    "fallecida `deceased_person` TINYINT(1) NOT NULL DEFAULT 0 — booleano con DEFAULT false que "
    "responde la omisión del alta (422 con valor no booleano), devuelto por TODOS los endpoints "
    "(migración `2026_10_02_120200`); además el PATCH del recurso genérico relaja a `sometimes` las "
    "reglas `required` de las columnas propias, de modo que editar el sector ya no exige arrastrar "
    "`months_per_year`.\n"
)

RF_ENT_DIRECTORS_OLD = (
    "- [ ] Directores (general y económico) referencian personas registradas; son modificables con "
    "auditoría.\n"
)

RF_ENT_DIRECTORS_NEW = (
    "- [x] Directores (general y económico) referencian personas registradas; son modificables con "
    "auditoría. FIX de usuario (Task 38/SGP-32): el GET del listado de entidades devuelve los DATOS "
    "del director general y del económico — las proyecciones COMPLETAS de Persona bajo `director` y "
    "`economic_director` (`PersonResource` reutilizado con carga anticipada, misma forma que "
    "`applicant`/`filed_by`), null cuando la entidad no los declara — igual que el detalle y las "
    "respuestas de alta/edición.\n"
)

patch(
    "Requisitos funcionales.md",
    [
        (RF_T37_ITEM_TAIL, RF_T38_ITEM),
        (RF_CAT_T31_TAIL, RF_CAT_T38_ITEM),
        (RF_ENT_DIRECTORS_OLD, RF_ENT_DIRECTORS_NEW),
    ],
)

# ---------------------------------------------------------------------------
# 2) Modelo de datos.md
# ---------------------------------------------------------------------------
MD_TYPES_ROW_OLD = (
    "| `pension_types` | — | EDAD/Por edad, INV/Por invalidez, SOB/Por sobrevivencia |"
)
MD_TYPES_ROW_NEW = (
    "| `pension_types` | `deceased_person TINYINT(1) NOT NULL DEFAULT 0` (Task 38) | "
    "EDAD/Por edad, INV/Por invalidez, SOB/Por sobrevivencia |"
)

MD_REGIMES_ROW_OLD = (
    "| `pension_regimes` | `months_per_year INT UNSIGNED NOT NULL CHECK (> 0)` | "
    "GEN/General (12); especiales a validar (P-02) |"
)
MD_REGIMES_ROW_NEW = (
    "| `pension_regimes` | `months_per_year INT UNSIGNED NOT NULL CHECK (> 0)`, "
    "`sector INT NULL` (Task 38) | GEN/General (12); especiales a validar (P-02) |"
)

MD_ER_TYPES_OLD = (
    '    PENSION_TYPES {\n'
    '        bigint id PK\n'
    '        varchar code "Unico"\n'
    '        varchar name\n'
    '    }\n'
)
MD_ER_TYPES_NEW = (
    '    PENSION_TYPES {\n'
    '        bigint id PK\n'
    '        varchar code "Unico"\n'
    '        varchar name\n'
    '        tinyint deceased_person "DEFAULT 0"\n'
    '    }\n'
)

MD_ER_REGIMES_OLD = (
    '    PENSION_REGIMES {\n'
    '        bigint id PK\n'
    '        varchar name "Unico"\n'
    '        int months_per_year\n'
    '    }\n'
)
MD_ER_REGIMES_NEW = (
    '    PENSION_REGIMES {\n'
    '        bigint id PK\n'
    '        varchar name "Unico"\n'
    '        int months_per_year\n'
    '        int sector "NULL"\n'
    '    }\n'
)

MD_57_COUNCIL_TAIL = (
    "| popular_council | VARCHAR(120) | SÍ | — | Consejo popular del promovente (Task 37): "
    "división territorial cubana, texto libre opcional (422 con 121 caracteres; omisión = NULL) |\n"
)
MD_57_TERMINATION = MD_57_COUNCIL_TAIL + (
    "| termination_date | DATE | SÍ | — | Fecha de desvinculación del promovente (Task 38/SGP-32, "
    "corrección de usuario): opcional en el wire con regla de forma Y-m-d única (422 con formato "
    "inválido; sin sonda semántica), omisión = NULL; migración `2026_10_02_120000` |\n"
)

MD_MIGRATIONS_OLD = (
    "`2026_10_02_110000_add_promovente_contact_and_internationalist_to_pension_cases_table` y "
    "`2026_10_02_110100_tighten_period_rules_on_service_records_table`):"
)
MD_MIGRATIONS_NEW = (
    "`2026_10_02_110000_add_promovente_contact_and_internationalist_to_pension_cases_table` y "
    "`2026_10_02_110100_tighten_period_rules_on_service_records_table`, "
    "`2026_10_02_120000_add_termination_date_to_pension_cases_table`, "
    "`2026_10_02_120100_add_sector_to_pension_regimes_table` y "
    "`2026_10_02_120200_add_deceased_person_to_pension_types_table`):"
)

MD_SEMANTICS_T37_TAIL = (
    "el LISTADO carga el promovente completo (regla 3, `PersonResource` reutilizado con carga "
    "anticipada)."
)
MD_SEMANTICS_T38 = (
    MD_SEMANTICS_T37_TAIL
    + " Desde la Task 38 (corrección de usuario, SGP-32) el expediente lleva además la fecha de "
    "desvinculación del promovente — `termination_date` DATE NULL (migración "
    "`2026_10_02_120000`): opcional en el wire (solo regla de forma Y-m-d, sin sonda semántica), "
    "omisión/null/'' persisten NULL normalizadas como el par de contacto y el 201, el detalle y el "
    "listado la devuelven."
)

MD_CATALOG_NOTE_OLD = (
    "`pension_regimes` incluye además `description VARCHAR(255) NULL` documentando la regla de "
    "cómputo del régimen."
)
MD_CATALOG_NOTE_NEW = (
    MD_CATALOG_NOTE_OLD
    + " Desde la Task 38 (corrección de usuario, SGP-32) dos catálogos de pensión ganan columnas "
    "propias servidas por la maquinaria genérica de `extraRules`: el régimen de jubilación lleva "
    "`sector INT NULL` (opcional, sin constraint — la corrección no declara dominio; migración "
    "`2026_10_02_120100`) y el tipo de pensión lleva persona fallecida `deceased_person "
    "TINYINT(1) NOT NULL DEFAULT 0` (paralelo de `applies_base_salary`: la omisión del alta cae en "
    "el DEFAULT false, y el modelo lleva el mismo default en memoria para que el 201 proyecte false "
    "sin recarga; migración `2026_10_02_120200`) — ambos devueltos por TODOS los endpoints del "
    "recurso genérico, y el PATCH relaja a `sometimes` las reglas `required` de las columnas propias "
    "de modo que editar el sector ya no exige arrastrar `months_per_year`."
)

MD_CHANGELOG_T37_TAIL = (
    "filas de columna, ER, lista de migraciones y semántica 5.7 actualizadas | Arq. Backend |\n"
)
MD_CHANGELOG_T38 = (
    MD_CHANGELOG_T37_TAIL
    + "| 1.24 | 2026-10-02 | Corrección de usuario (Task 38, SGP-32): el expediente gana la fecha "
    "de desvinculación del promovente `termination_date` DATE NULL (migración `2026_10_02_120000`; "
    "opcional con regla de forma Y-m-d, omisión = NULL, devuelta en 201/detalle/listado); el régimen "
    "de jubilación gana `sector INT NULL` (migración `2026_10_02_120100`) y el tipo de pensión gana "
    "persona fallecida `deceased_person TINYINT(1) NOT NULL DEFAULT 0` (migración "
    "`2026_10_02_120200`), ambos devueltos por TODOS los endpoints del catálogo genérico con el "
    "PATCH relaxado a `sometimes`; y el GET del listado de entidades devuelve los DATOS del director "
    "general y el económico como proyecciones completas de Persona (null sin directores) — filas de "
    "columna, ER, nota de catálogos, lista de migraciones y semántica 5.7 actualizadas | "
    "Arq. Backend |\n"
)

patch(
    "Modelo de datos.md",
    [
        (MD_TYPES_ROW_OLD, MD_TYPES_ROW_NEW),
        (MD_REGIMES_ROW_OLD, MD_REGIMES_ROW_NEW),
        (MD_ER_TYPES_OLD, MD_ER_TYPES_NEW),
        (MD_ER_REGIMES_OLD, MD_ER_REGIMES_NEW),
        (MD_57_COUNCIL_TAIL, MD_57_TERMINATION),
        (MD_MIGRATIONS_OLD, MD_MIGRATIONS_NEW),
        (MD_SEMANTICS_T37_TAIL, MD_SEMANTICS_T38),
        (MD_CATALOG_NOTE_OLD, MD_CATALOG_NOTE_NEW),
        (MD_CHANGELOG_T37_TAIL, MD_CHANGELOG_T38),
    ],
)

# ---------------------------------------------------------------------------
# 3) Diseño de arquitectura.md
# ---------------------------------------------------------------------------
ARQ_ENDPOINTS_CATALOGS_OLD = (
    "CRUD de catálogos uniformes (recurso genérico ADR-15: 16 tipos, todos con `code` desde "
    "Task 31) |"
)
ARQ_ENDPOINTS_CATALOGS_NEW = (
    "CRUD de catálogos uniformes (recurso genérico ADR-15: 16 tipos, todos con `code` desde "
    "Task 31); Task 38/SGP-32: `pension-regimes` devuelve `sector` INT NULL opcional y "
    "`pension-types` devuelve `deceased_person` booleano (DEFAULT false) en TODOS los endpoints, y "
    "el PATCH relaja a `sometimes` las reglas `required` de las columnas propias (editar el sector "
    "ya no exige arrastrar `months_per_year`) |"
)

ARQ_ENDPOINTS_ENTITIES_OLD = (
    "directores referencian personas registradas; árbol RF-ENT-005 de 5 niveles con corte "
    "anunciado (`deeper`) |"
)
ARQ_ENDPOINTS_ENTITIES_NEW = (
    "directores referencian personas registradas — Task 38/SGP-32 (FIX de usuario): el listado y "
    "el detalle devuelven sus DATOS como proyecciones COMPLETAS de Persona (`director`, "
    "`economic_director`, null sin directores); árbol RF-ENT-005 de 5 niveles con corte anunciado "
    "(`deeper`) |"
)

ARQ_ENDPOINTS_CASES_OLD = (
    "omisión = NULL, devueltos en 201/detalle/listado), subregistros declarados en la MISMA "
    "transacción"
)
ARQ_ENDPOINTS_CASES_NEW = (
    "omisión = NULL, devueltos en 201/detalle/listado), fecha de desvinculación del promovente "
    "opcional (Task 38/SGP-32: `termination_date` DATE, regla de forma Y-m-d, omisión = NULL, "
    "devuelta en 201/detalle/listado), subregistros declarados en la MISMA transacción"
)

ARQ_CHANGELOG_T37_TAIL = (
    "filas de endpoints del alta, detalle y subregistros actualizadas | Arq. Backend |\n"
)
ARQ_CHANGELOG_T38 = (
    ARQ_CHANGELOG_T37_TAIL
    + "| 1.30 | 2026-10-02 | Corrección de usuario (Task 38, SGP-32): el alta del expediente recibe "
    "la fecha de desvinculación del promovente `termination_date` (DATE opcional, regla de forma "
    "Y-m-d, omisión = NULL) devuelta en 201/detalle/listado; los catálogos de pensión ganan "
    "columnas propias devueltas por TODOS los endpoints (`pension-regimes.sector` INT NULL "
    "opcional; `pension-types.deceased_person` booleano con DEFAULT false) con el PATCH del recurso "
    "genérico relaxado a `sometimes` para las columnas propias; y el listado de entidades devuelve "
    "los DATOS del director general y el económico como proyecciones completas de Persona — filas "
    "de endpoints del alta, catálogos y entidades actualizadas | Arq. Backend |\n"
)

patch(
    "Diseño de arquitectura.md",
    [
        (ARQ_ENDPOINTS_CATALOGS_OLD, ARQ_ENDPOINTS_CATALOGS_NEW),
        (ARQ_ENDPOINTS_ENTITIES_OLD, ARQ_ENDPOINTS_ENTITIES_NEW),
        (ARQ_ENDPOINTS_CASES_OLD, ARQ_ENDPOINTS_CASES_NEW),
        (ARQ_CHANGELOG_T37_TAIL, ARQ_CHANGELOG_T38),
    ],
)

# ---------------------------------------------------------------------------
# 4) 04_Plan_de_desarrollo.md
# ---------------------------------------------------------------------------
PLAN_T31_ITEM_TAIL = (
    "*(suite 1040/3442 contra MySQL real; fumiga HTTP `scripts/smoke_task31_catalogs_entity.php` "
    "con 25 comprobaciones)*\n"
)
PLAN_T38_CATALOGS = PLAN_T31_ITEM_TAIL + (
    "✅ 2026-10-02 AMPLIADA por la corrección de usuario de Task 38 (SGP-32): columnas propias de "
    "los catálogos de pensión servidas por la maquinaria genérica de `extraRules` — "
    "`pension_regimes.sector` INT NULL opcional (migración `2026_10_02_120100`) y "
    "`pension_types.deceased_person` TINYINT(1) NOT NULL DEFAULT 0 (migración "
    "`2026_10_02_120200`, con el default espejado en memoria para que el 201 proyecte false sin "
    "recarga) devueltos por TODOS los endpoints del recurso genérico, y el PATCH relajado a "
    "`sometimes` para las columnas propias — más el FIX del listado de entidades: `GET /entities` "
    "devuelve los DATOS del director general y el económico como proyecciones completas de Persona "
    "(`director`/`economic_director`, null sin directores, `PersonResource` reutilizado con carga "
    "anticipada en el WITH del repositorio); suite 1069/3635, fumiga de expedientes con 54 "
    "comprobaciones\n"
)

PLAN_S52_T37_TAIL = "suite 1062/3562, fumiga con 4 comprobaciones propias\n"
PLAN_S52_T38 = (
    PLAN_S52_T37_TAIL
    + " ✅ 2026-10-02 AMPLIADA por la corrección de usuario de Task 38 (SGP-32): fecha de "
    "desvinculación del promovente — `termination_date` DATE NULL (migración "
    "`2026_10_02_120000`), opcional en el wire con regla de forma Y-m-d única (422 con formato "
    "inválido; sin sonda semántica), omisión/null/'' persisten NULL y el 201, el detalle y el "
    "listado la devuelven; suite 1069/3635, fumiga con 3 comprobaciones propias\n"
)

PLAN_CHANGELOG_T37_TAIL = "fumiga de expedientes ampliada TODO OK | Arq. Backend |\n"
PLAN_CHANGELOG_T38 = (
    PLAN_CHANGELOG_T37_TAIL
    + "| 1.22 | 2026-10-02 | Corrección de usuario (Task 38, SGP-32; ítems S4.1 y S5.2 "
    "ampliados): fecha de desvinculación del promovente (`termination_date` DATE NULL, migración "
    "`2026_10_02_120000`) opcional con regla de forma Y-m-d y devuelta en 201/detalle/listado; "
    "columnas propias de los catálogos de pensión (`pension_regimes.sector` INT NULL, "
    "`2026_10_02_120100`; `pension_types.deceased_person` DEFAULT false, `2026_10_02_120200`) "
    "devueltas por TODOS los endpoints del catálogo genérico con el PATCH relaxado a `sometimes`; "
    "FIX del listado de entidades devolviendo los DATOS del director general y el económico como "
    "proyecciones completas de Persona — suite 1069/3635 contra MySQL real, Pint/PHPStan "
    "8/deptrac en verde y fumiga de expedientes ampliada a 54 comprobaciones TODO OK | "
    "Arq. Backend |\n"
)

patch(
    "04_Plan_de_desarrollo.md",
    [
        (PLAN_T31_ITEM_TAIL, PLAN_T38_CATALOGS),
        (PLAN_S52_T37_TAIL, PLAN_S52_T38),
        (PLAN_CHANGELOG_T37_TAIL, PLAN_CHANGELOG_T38),
    ],
)

print("\nTask 38: documentación parcheada (4 archivos).")
