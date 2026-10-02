#!/usr/bin/env python3
"""Task 37 (SGP-31) — parches de documentación.

Corrección de usuario: (A) el expediente gana telefono/consejo popular del
promovente (phone, popular_council — textos opcionales) y la marca
internacionalista (internationalist, booleana OBLIGATORIA); (B) revisión de
los subregistros de servicio — fecha de fin OBLIGATORIA y estrictamente
posterior al inicio y SIN solapamiento entre períodos (422 en ambos puntos
de entrada; sin vínculos abiertos).

Cuatro archivos, parches con ancla única y aserción de unicidad (patrón de
las Tasks 34-36).
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
RF_EXP_001_ANCHOR = (
    "- [x] Persona por (corrección de usuario, Task 34, ampliada por Task 35 y renombrada a columna "
    "inglesa por Task 36): campo `filed_by_person_id` BIGINT UNSIGNED NULL con FK a `people` — "
    "REFERENCIA a la persona REGISTRADA que presenta o gestiona el expediente cuando no es el propio "
    "proponente (un familiar, un apoderado, un gestor) —: el alta la recibe como `filed_by_person_id` "
    "(422 si la persona no existe o está desactivada; la omisión persiste NULL) y el 201, el detalle y "
    "el listado devuelven el id más la proyección COMPLETA de la persona bajo `filed_by` (misma forma "
    "que `applicant`, regla de usuario 3) (migraciones `2026_10_01_130000` + `2026_10_01_140000` + "
    "renombre inglés `2026_10_02_100100`).\n"
)

RF_EXP_001_NEW = RF_EXP_001_ANCHOR + (
    "- [x] Internacionalista y contacto del promovente (corrección de usuario, Task 37/SGP-31): la "
    "marca `internationalist` TINYINT(1) NOT NULL DEFAULT 0 — booleana OBLIGATORIA en el wire, paralelo "
    "del par de Ejército Rebelde (422 si se omite) — y el par de contacto `phone` VARCHAR(30) NULL y "
    "`popular_council` VARCHAR(120) NULL (teléfono y consejo popular del promovente: textos libres "
    "opcionales, 422 con 31/121 caracteres, omisión = NULL): el alta los recibe (migración "
    "`2026_10_02_110000`) y el 201, el detalle y el listado los devuelven.\n"
)

RF_EXP_003_OLD = (
    "- [ ] Cada servicio declara entidad, fecha de inicio, fecha de fin opcional y marcador de coletilla.\n"
)

RF_EXP_003_HEAD_NEW = (
    "- [x] Cada servicio declara entidad, fecha de inicio, fecha de fin OBLIGATORIA y marcador de "
    "coletilla (corrección de usuario, Task 37/SGP-31: el vínculo siempre está CERRADO — no existe el "
    "vínculo vigente de fin NULL; migración `2026_10_02_110100` con `end_date` DATE NOT NULL y CHECK "
    "`end_date > start_date`).\n"
)

RF_EXP_003_ORDER_OLD = "- [ ] La fecha de fin, si existe, es posterior o igual a la de inicio.\n"

RF_EXP_003_ORDER_NEW = (
    "- [x] La fecha de fin es OBLIGATORIA y ESTRICTAMENTE posterior a la de inicio (Task 37: 422 sobre "
    "`end_date` en el alta individual y sobre `service_records.N.end_date` en el payload anidado, con "
    "el CHECK `end_date > start_date` como última línea — un servicio de un día, fin igual al inicio, "
    "es 422).\n"
)

RF_EXP_003_OVERLAP_OLD = (
    "- [ ] El sistema detecta solapamientos de períodos dentro del expediente y servicios sin cerrar "
    "con fecha de fin.\n"
)

RF_EXP_003_OVERLAP_NEW = (
    "- [x] NINGÚN par de períodos del expediente comparte un día (Task 37, corrección de usuario sobre "
    "el «detecta y advierte» del Sprint 5): los solapamientos se RECHAZAN con 422 en los DOS puntos de "
    "entrada — filas simultáneas del payload anidado (sobre `service_records`, nombrando los pares de "
    "filas) y alta individual contra los subregistros almacenados (sobre `end_date`, nombrando los "
    "registros cruzados) — mediante el dominio puro `ServicePeriods` (días inclusivos: el día siguiente "
    "al fin de un período arranca limpio); el objeto `warnings` queda solo con los años salariales "
    "interiores ausentes (RF-EXP-002).\n"
)

patch(
    "Requisitos funcionales.md",
    [
        (RF_EXP_001_ANCHOR, RF_EXP_001_NEW),
        (RF_EXP_003_OLD, RF_EXP_003_HEAD_NEW),
        (RF_EXP_003_ORDER_OLD, RF_EXP_003_ORDER_NEW),
        (RF_EXP_003_OVERLAP_OLD, RF_EXP_003_OVERLAP_NEW),
    ],
)

# ---------------------------------------------------------------------------
# 2) Modelo de datos.md
# ---------------------------------------------------------------------------

MD_CASE_FILED_BY_ROW = (
    "| filed_by_person_id | BIGINT UNSIGNED | SÍ | FK → people | Persona por (Task 35; renombrada a "
    "columna inglesa por Task 36): persona REGISTRADA que presenta o gestiona el expediente cuando no "
    "es el propio proponente — sonda sobre la superficie ACTIVA del registro (desactivada = 422), "
    "omisión = NULL, restrictOnDelete |\n"
)

MD_CASE_NEW_ROWS = (
    "| internationalist | TINYINT(1) | NO | DEFAULT 0 | Internacionalista (Task 37/SGP-31, corrección "
    "de usuario): el promovente cumplió misión internacionalista — booleana OBLIGATORIA en el wire "
    "(paralelo de `rebel_army_member`; 422 si se omite), DEFAULT 0 para escrituras fuera del wire |\n"
    + MD_CASE_FILED_BY_ROW
    + "| phone | VARCHAR(30) | SÍ | — | Teléfono de contacto del promovente (Task 37): texto libre "
    "opcional (422 con 31 caracteres; omisión = NULL) |\n"
    "| popular_council | VARCHAR(120) | SÍ | — | Consejo popular del promovente (Task 37): división "
    "territorial cubana, texto libre opcional (422 con 121 caracteres; omisión = NULL) |\n"
)

MD_SR_END_DATE_ROW = (
    "| end_date | DATE | SÍ | — | Fin del vínculo; NULL = vigente; CHECK ≥ start_date |\n"
)

MD_SR_END_DATE_NEW = (
    "| end_date | DATE | NO | CHECK > start_date | Fin del vínculo, OBLIGATORIO y estrictamente "
    "posterior al inicio (Task 37/SGP-31, corrección de usuario: el vínculo vigente de fin NULL dejó "
    "de existir; migración `2026_10_02_110100` endureció el CHECK de ≥ a >) |\n"
)

MD_SR_TRAILER = (
    "Solapamientos y huecos se validan en la capa de dominio (RF-EXP-003); MySQL no los expresa como "
    "constraint.\n"
)

MD_SR_TRAILER_NEW = (
    "El solapamiento se RECHAZA en la capa de aplicación (Task 37, corrección de usuario sobre el "
    "«detecta y advierte» del Sprint 5): `ServicePeriods` sondea los DOS puntos de entrada — filas "
    "anidadas del alta (pares, sobre `service_records`) y alta individual contra lo almacenado (sobre "
    "`end_date`) — con 422 antes de escribir; los períodos son cerrados y disjuntos por construcción, "
    "de modo que el objeto `warnings` solo conserva los años salariales interiores ausentes "
    "(RF-EXP-002); MySQL no expresa el solapamiento como constraint.\n"
)

MD_MERMAID_END = '        date end_date "NULL = vigente"\n'
MD_MERMAID_END_NEW = '        date end_date "obligatoria, > start_date"\n'

MD_SEM_MIGRATIONS_OLD = (
    "`2026_10_02_100000_rename_forma_declaracion_to_declaration_form_on_service_records_table` y "
    "`2026_10_02_100100_rename_persona_por_id_to_filed_by_person_id_on_pension_cases_table`)"
)

MD_SEM_MIGRATIONS_NEW = (
    "`2026_10_02_100000_rename_forma_declaracion_to_declaration_form_on_service_records_table` y "
    "`2026_10_02_100100_rename_persona_por_id_to_filed_by_person_id_on_pension_cases_table`, "
    "`2026_10_02_110000_add_promovente_contact_and_internationalist_to_pension_cases_table` y "
    "`2026_10_02_110100_tighten_period_rules_on_service_records_table`)"
)

MD_SEM_PERIODS_OLD = (
    "—, y el orden de fechas de servicios está respaldado por CHECK `chk_service_records_dates` "
    "(RN-006) y los solapamientos y vínculos abiertos — no expresables como constraint — se detectan y "
    "ADVIERTE"
)

MD_SEM_PERIODS_NEW = (
    "—, y desde la Task 37 (corrección de usuario, SGP-31) todo período de servicio está CERRADO y "
    "DISJUNTO: `end_date` es NOT NULL con CHECK `chk_service_records_dates` endurecido a "
    "`end_date > start_date` (migración `2026_10_02_110100`) y el solapamiento — no expresable como "
    "constraint — se RECHAZA con 422 por el dominio puro `ServicePeriods` en los dos puntos de entrada "
    "(filas anidadas del alta por pares sobre `service_records`; alta individual contra lo almacenado "
    "sobre `end_date` nombrando los registros cruzados; días inclusivos, el día siguiente al fin "
    "arranca limpio), de modo que los vínculos abiertos no existen y el objeto `warnings` solo "
    "conserva el análisis salarial de `SalarySeries` (años interiores ausentes) — antes se ADVIERTE"
)

MD_CHANGELOG_LAST = (
    "| 1.22 | 2026-10-02 | Corrección de usuario (Task 36, SGP-30): las columnas añadidas en español "
    "por las correcciones Task 32-35 pasan al patrón INGLÉS de todas las columnas previas (ADR-03, "
    "vinculante para todo el desarrollo) — `forma_declaracion` → `declaration_form` (migración "
    "`2026_10_02_100000`, CHECK `chk_service_records_declaration_form`) y `persona_por_id` → "
    "`filed_by_person_id` (migración `2026_10_02_100100`, FK "
    "`pension_cases_filed_by_person_id_foreign`); el wire, las proyecciones (`filed_by`) y los "
    "schemas OA siguen los nombres ingleses; los valores del enum (Documental\\|Testifical) no cambian "
    "— son valores de dominio definidos por el usuario; filas de columna, lista de migraciones y "
    "semántica 5.7 actualizadas | Arq. Backend |\n"
)

MD_CHANGELOG_123 = MD_CHANGELOG_LAST + (
    "| 1.23 | 2026-10-02 | Corrección de usuario (Task 37, SGP-31): el expediente gana la marca "
    "`internationalist` TINYINT(1) NOT NULL DEFAULT 0 (booleana OBLIGATORIA en el wire, paralelo del "
    "par rebelde) y el par de contacto del promovente `phone` VARCHAR(30) NULL / `popular_council` "
    "VARCHAR(120) NULL (migración `2026_10_02_110000`; textos opcionales con techo, omisión = NULL), "
    "y los subregistros de servicio pasan a períodos CERRADOS y DISJUNTOS — `end_date` DATE NOT NULL "
    "con CHECK `end_date > start_date` y solapamiento rechazado con 422 por `ServicePeriods` en los "
    "dos puntos de entrada (migración `2026_10_02_110100`; sin vínculos abiertos, `warnings` queda "
    "solo con los años salariales ausentes) —; filas de columna, ER, lista de migraciones y semántica "
    "5.7 actualizadas | Arq. Backend |\n"
)

patch(
    "Modelo de datos.md",
    [
        (MD_CASE_FILED_BY_ROW, MD_CASE_NEW_ROWS),
        (MD_SR_END_DATE_ROW, MD_SR_END_DATE_NEW),
        (MD_SR_TRAILER, MD_SR_TRAILER_NEW),
        (MD_MERMAID_END, MD_MERMAID_END_NEW),
        (MD_SEM_MIGRATIONS_OLD, MD_SEM_MIGRATIONS_NEW),
        (MD_SEM_PERIODS_OLD, MD_SEM_PERIODS_NEW),
        (MD_CHANGELOG_LAST, MD_CHANGELOG_123),
    ],
)

# ---------------------------------------------------------------------------
# 3) Diseño de arquitectura.md
# ---------------------------------------------------------------------------

ARQ_STORE_OLD = (
    "subregistros declarados en la MISMA transacción (S5.5) — incluidos los conceptos de ingreso "
    "(regla 5) y la forma de declaración por fila de los servicios (Task 33: "
    "`service_records.*.declaration_form`, `in:Documental,Testifical`, omisión = Documental, "
    "desconocido 422 todo-o-nada; columna inglesa desde Task 36) — y objeto `warnings` con el análisis "
    "de evidencia |"
)

ARQ_STORE_NEW = (
    "marca de internacionalista del promovente OBLIGATORIA y par de contacto opcional (Task 37/SGP-31, "
    "corrección de usuario: `internationalist` booleana requerida —422 si se omite, paralelo del par "
    "rebelde— más `phone` VARCHAR(30) y `popular_council` VARCHAR(120), omisión = NULL, devueltos en "
    "201/detalle/listado), subregistros declarados en la MISMA transacción (S5.5) — incluidos los "
    "conceptos de ingreso (regla 5), la forma de declaración por fila de los servicios (Task 33: "
    "`service_records.*.declaration_form`, `in:Documental,Testifical`, omisión = Documental, "
    "desconocido 422 todo-o-nada; columna inglesa desde Task 36) y las reglas de período de la Task 37 "
    "(`end_date` OBLIGATORIA, estrictamente posterior al inicio y SIN solapamiento entre filas: 422 "
    "todo-o-nada) — y objeto `warnings` con el análisis de evidencia |"
)

ARQ_SHOW_OLD = (
    "Detalle con subregistros, proyección COMPLETA del proponente (regla 3) y `warnings` (huecos "
    "salariales, solapes, vínculos abiertos); el historial llega con las transiciones de S6 |"
)

ARQ_SHOW_NEW = (
    "Detalle con subregistros, proyección COMPLETA del proponente (regla 3) y `warnings` (huecos "
    "salariales; Task 37: solapes y vínculos abiertos imposibles por construcción — períodos cerrados "
    "y disjuntos); el historial llega con las transiciones de S6 |"
)

ARQ_SUB_OLD = (
    "importes por Money (RN-005), orden de fechas sondeado; los CONCEPTOS DE INGRESO (regla 5) "
    "declaran un valor por par caso-concepto (UNIQUE, 422 semántico sobre `income_concept_id`) con "
    "catálogo activo sondeado y DECIMAL(12,2) no negativo; solapes y vínculos abiertos viajan en "
    "`warnings` (advertencia, nunca bloque); forma de declaración `Documental|Testifical` en los "
    "servicios (Task 32: default Documental; Task 33: también por fila en el payload anidado del "
    "alta, jamás descarte silencioso); las bajas son físicas y auditadas con valores previos; PATCH "
    "de filas diferido a S6 |"
)

ARQ_SUB_NEW = (
    "importes por Money (RN-005); los SERVICIOS (Task 37, corrección de usuario) exigen `end_date` "
    "OBLIGATORIA, ESTRICTAMENTE posterior a `start_date` y SIN solapamiento con ningún subregistro "
    "almacenado del expediente (422 sobre `end_date` nombrando los registros cruzados; CHECK "
    "`end_date > start_date` como última línea — sin vínculos abiertos); los CONCEPTOS DE INGRESO "
    "(regla 5) declaran un valor por par caso-concepto (UNIQUE, 422 semántico sobre "
    "`income_concept_id`) con catálogo activo sondeado y DECIMAL(12,2) no negativo; forma de "
    "declaración `Documental|Testifical` en los servicios (Task 32: default Documental; Task 33: "
    "también por fila en el payload anidado del alta, jamás descarte silencioso); las bajas son "
    "físicas y auditadas con valores previos; PATCH de filas diferido a S6 |"
)

ARQ_CHANGELOG_LAST = (
    "| 1.28 | 2026-10-02 | Corrección de usuario (Task 36, SGP-30): las columnas añadidas en español "
    "por las correcciones Task 32-35 pasan al patrón INGLÉS de todas las columnas previas (ADR-03, "
    "vinculante para todo el desarrollo) — `forma_declaracion` → `declaration_form` (migración "
    "`2026_10_02_100000`, CHECK renombrado) y `persona_por_id` → `filed_by_person_id` (migración "
    "`2026_10_02_100100`, FK renombrada); el wire (`service_records.*.declaration_form`, "
    "`filed_by_person_id`), la proyección `filed_by` y los schemas OA del requestBody y del response "
    "quedan en inglés; fila de endpoints del alta actualizada | Arq. Backend |\n"
)

ARQ_CHANGELOG_129 = ARQ_CHANGELOG_LAST + (
    "| 1.29 | 2026-10-02 | Corrección de usuario (Task 37, SGP-31): el alta del expediente recibe la "
    "marca `internationalist` (booleana OBLIGATORIA, paralelo del par rebelde) y el par de contacto "
    "del promovente `phone`/`popular_council` (opcional, techos 30/120) devueltos en "
    "201/detalle/listado; los subregistros de servicio pasan a períodos CERRADOS y DISJUNTOS — "
    "`end_date` obligatoria, estrictamente posterior al inicio y solapamiento rechazado con 422 en "
    "ambos puntos de entrada, con `warnings` reducido a los años salariales ausentes —; filas de "
    "endpoints del alta, detalle y subregistros actualizadas | Arq. Backend |\n"
)

patch(
    "Diseño de arquitectura.md",
    [
        (ARQ_STORE_OLD, ARQ_STORE_NEW),
        (ARQ_SHOW_OLD, ARQ_SHOW_NEW),
        (ARQ_SUB_OLD, ARQ_SUB_NEW),
        (ARQ_CHANGELOG_LAST, ARQ_CHANGELOG_129),
    ],
)

# ---------------------------------------------------------------------------
# 4) 04_Plan_de_desarrollo.md
# ---------------------------------------------------------------------------

PLAN_S52_OLD = (
    "suite 1050/3495, fumiga de expedientes con la persona por en 4 comprobaciones ✅ 2026-10-02 "
    "RENOMBRADA a columnas inglesas por la corrección de usuario de Task 36 (SGP-30, patrón ADR-03 "
    "vinculante para todo el desarrollo): forma_declaracion → declaration_form y persona_por_id → "
    "filed_by_person_id (migraciones `2026_10_02_100000`/`100100` con CHECK y FK renombrados a "
    "inglés; wire, proyección `filed_by`, OA y fumiga actualizados; los valores Documental|Testifical "
    "no cambian); suite 1050/3495"
)

PLAN_S52_NEW = (
    "suite 1050/3495, fumiga de expedientes con la persona por en 4 comprobaciones ✅ 2026-10-02 "
    "RENOMBRADA a columnas inglesas por la corrección de usuario de Task 36 (SGP-30, patrón ADR-03 "
    "vinculante para todo el desarrollo): forma_declaracion → declaration_form y persona_por_id → "
    "filed_by_person_id (migraciones `2026_10_02_100000`/`100100` con CHECK y FK renombrados a "
    "inglés; wire, proyección `filed_by`, OA y fumiga actualizados; los valores Documental|Testifical "
    "no cambian); suite 1050/3495 ✅ 2026-10-02 AMPLIADA por la corrección de usuario de Task 37 "
    "(SGP-31): internacionalista del promovente (`internationalist` TINYINT(1) NOT NULL DEFAULT 0, "
    "booleana OBLIGATORIA en el wire — 422 si se omite, paralelo de `rebel_army_member`) y par de "
    "contacto (`phone` VARCHAR(30) / `popular_council` VARCHAR(120), opcionales con techo, omisión = "
    "NULL) recibidos en el alta y devueltos en 201/detalle/listado (migración "
    "`2026_10_02_110000`); suite 1062/3562, fumiga con 4 comprobaciones propias"
)

PLAN_S53_OLD = (
    "- [x] Subregistros con validaciones RN-005/RN-006 (importes `DECIMAL(12,2)`, periodos coherentes) "
    "y solapamiento de servicios detectado y advertido. ✅ 2026-09-28 (Money de Shared en todos los "
    "importes; techo del año = año actual+1 contra `ClockInterface`; orden de fechas sondeado antes "
    "del CHECK; `SalarySeries` advierte huecos interiores y `ServicePeriods` solapes con días "
    "inclusivos y vínculos abiertos — advertencia, nunca bloque, viajan como objeto `warnings` junto "
    "a `data`)"
)

PLAN_S53_NEW = (
    "- [x] Subregistros con validaciones RN-005/RN-006 (importes `DECIMAL(12,2)`, periodos coherentes) "
    "y solapamiento de servicios detectado y advertido. ✅ 2026-09-28 (Money de Shared en todos los "
    "importes; techo del año = año actual+1 contra `ClockInterface`; orden de fechas sondeado antes "
    "del CHECK; `SalarySeries` advierte huecos interiores y `ServicePeriods` solapes con días "
    "inclusivos y vínculos abiertos — advertencia, nunca bloque, viajan como objeto `warnings` junto "
    "a `data`) ✅ 2026-10-02 CORREGIDO por el usuario (Task 37/SGP-31): los períodos de servicio son "
    "CERRADOS y DISJUNTOS — `end_date` DATE NOT NULL OBLIGATORIA y ESTRICTAMENTE posterior al inicio "
    "(CHECK `end_date > start_date`, migración `2026_10_02_110100`; 422 si falta, si es igual o "
    "anterior al inicio) y NINGÚN par de subregistros comparte un día (`ServicePeriods::"
    "overlappingPairs` para las filas anidadas del alta con 422 sobre `service_records` nombrando "
    "los pares, `idsOverlappingWith` para el alta individual con 422 sobre `end_date` nombrando los "
    "registros cruzados; días inclusivos: el día siguiente al fin arranca limpio) — el vínculo "
    "vigente no existe y `warnings` queda solo con los años salariales interiores ausentes; suite "
    "1062/3562, fumiga con 9 comprobaciones de período"
)

PLAN_CHANGELOG_LAST = (
    "| 1.20 | 2026-10-02 | Corrección de usuario (Task 36, SGP-30; ítems S5.2 y S5.4 ampliados): las "
    "columnas añadidas en español por las correcciones Task 32-35 pasan al patrón INGLÉS de todas las "
    "columnas previas (ADR-03, vinculante para todo el desarrollo) — `forma_declaracion` → "
    "`declaration_form` (migración `2026_10_02_100000`, CHECK renombrado) y `persona_por_id` → "
    "`filed_by_person_id` (migración `2026_10_02_100100`, FK renombrada); wire, proyección "
    "`filed_by`, OA y fumiga en inglés; los valores Documental\\|Testifical no cambian — suite "
    "1050/3495 contra MySQL real, Pint/PHPStan 8/deptrac en verde y fumiga TODO OK | Arq. Backend |\n"
)

PLAN_CHANGELOG_121 = PLAN_CHANGELOG_LAST + (
    "| 1.21 | 2026-10-02 | Corrección de usuario (Task 37, SGP-31; ítems S5.2 y S5.3 ampliados): "
    "internacionalista del promovente (`internationalist` TINYINT(1) NOT NULL DEFAULT 0, booleana "
    "OBLIGATORIA en el wire) y par de contacto `phone`/`popular_council` (VARCHAR(30)/(120) "
    "opcionales; migración `2026_10_02_110000`) recibidos en el alta y devueltos en "
    "201/detalle/listado; subregistros de servicio con períodos CERRADOS y DISJUNTOS — `end_date` "
    "NOT NULL estrictamente posterior (CHECK `end_date > start_date`, migración "
    "`2026_10_02_110100`) y solapamiento rechazado con 422 en ambos puntos de entrada por "
    "`ServicePeriods` (sin vínculos abiertos; `warnings` reducido a los años salariales ausentes) — "
    "suite 1062/3562 contra MySQL real, Pint/PHPStan 8/deptrac en verde y fumiga de expedientes "
    "ampliada TODO OK | Arq. Backend |\n"
)

patch(
    "04_Plan_de_desarrollo.md",
    [
        (PLAN_S52_OLD, PLAN_S52_NEW),
        (PLAN_S53_OLD, PLAN_S53_NEW),
        (PLAN_CHANGELOG_LAST, PLAN_CHANGELOG_121),
    ],
)

print("\nTask 37: parches de documentación aplicados (4 archivos).")
