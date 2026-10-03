#!/usr/bin/env python3
"""Patch the four SGP documents for Task 42 / SGP-36 (user correction).

DOCUMENTATION-ONLY PHASE: the user asked to adjust the development docs and
PREPARE the implementation, waiting for validation before touching code.
Every hunk is therefore worded as "documentada, implementación pendiente de
validación" and the migration names are marked as "prevista" (planned).

The four corrections:
1. The payment_types catalog (Tipo de pago) is ELIMINATED.
2. agency_types (Tipo de agencia) gains forma de pago payment_form, an enum
   with the user's LITERAL values, without tildes, exactly as typed in the
   correction: 'tarjeta magnetica' and 'Nomina Electronica'.
3. pension_cases (expediente) gains two promovente data groups:
   residence (current_address + residence_province_id +
   residence_municipality_id, RN-04 coherent pair) and banking
   (bank_account + collection_agency_type_id + collection_agency_id,
   coherent pair over agencies(id, agency_type_id)).
4. income_concept_records gains applied_percentage DECIMAL(5,2) (the user's
   Double lands as an exact decimal per RN-005), CHECK 0-100.

Anchored, asserted patches (the Task 40/41 pattern): every anchor must be
unique and the patch must not be pre-applied; the script fails loudly
instead of double-applying.
"""

from pathlib import Path

ROOT = Path("/home/z/my-project/download")

MODELO = ROOT / "Modelo de datos.md"
REQUISITOS = ROOT / "Requisitos funcionales.md"
ARQUITECTURA = ROOT / "Diseño de arquitectura.md"
PLAN = ROOT / "04_Plan_de_desarrollo.md"

# ---------------------------------------------------------------------------
# Modelo de datos.md
# ---------------------------------------------------------------------------
MODELO_PATCHES: list[tuple[str, str]] = [
    # 2. Analysis table — the H-02/H-03 row also covers the new percentage.
    (
        "| H-02/H-03 dinero Double/int | `last_salary`, `earned_salary`, `amount` (cuantía) y montos de pago: `DECIMAL(12,2)` |",
        "| H-02/H-03 dinero Double/int | `last_salary`, `earned_salary`, `amount` (cuantía) y montos de pago: `DECIMAL(12,2)`; el porciento a aplicar del concepto de ingreso sigue el mismo patrón (Task 42/SGP-36: el Double declarado aterriza como `applied_percentage DECIMAL(5,2)` exacto) |",
    ),
    # 3. Glossary — the Tipo de pago row disappears.
    (
        "| Tipo de pago | `payment_types` | `PaymentType` |\n",
        "",
    ),
    # 4.1 Panorama — income concept records + the new promovente relations.
    (
        '    PENSION_CASES ||--o{ PENSION_CASE_HISTORIES : "audita"\n'
        '    ENTITIES ||--o{ SERVICE_RECORDS : "entidad empleadora"',
        '    PENSION_CASES ||--o{ INCOME_CONCEPT_RECORDS : "declara"\n'
        '    PENSION_CASES ||--o{ PENSION_CASE_HISTORIES : "audita"\n'
        '    PROVINCES ||--o{ PENSION_CASES : "residencia del promovente"\n'
        '    MUNICIPALITIES ||--o{ PENSION_CASES : "residencia del promovente"\n'
        '    AGENCY_TYPES ||--o{ PENSION_CASES : "cobro del promovente"\n'
        '    AGENCIES ||--o{ PENSION_CASES : "cobro del promovente"\n'
        '    ENTITIES ||--o{ SERVICE_RECORDS : "entidad empleadora"',
    ),
    # 4.1 Panorama — the PAYMENT_TYPES relation line disappears.
    (
        '    PAYMENT_TYPES ||--o{ PENSION_PAYMENTS : "clasifica"\n'
        '    PEOPLE ||--o| USERS : "vinculada"',
        '    PEOPLE ||--o| USERS : "vinculada"',
    ),
    # 4.2 ER — agency_types gains the payment_form attribute.
    (
        '    AGENCY_TYPES {\n'
        '        bigint id PK\n'
        '        varchar code "Unico"\n'
        '        varchar name\n'
        '    }',
        '    AGENCY_TYPES {\n'
        '        bigint id PK\n'
        '        varchar code "Unico"\n'
        '        varchar name\n'
        '        varchar payment_form "tarjeta magnetica o Nomina Electronica, NULL (Task 42)"\n'
        '    }',
    ),
    # 4.5 ER — PENSION_CASES catches up with the current schema plus the six
    # new promovente columns (the entity was stale since ~Task 34).
    (
        '        bigint scientific_category_id FK\n'
        '        decimal last_salary "12,2"\n'
        '        bigint approval_legal_basis_id FK "NULL"',
        '        bigint scientific_category_id FK\n'
        '        bigint pension_type_id FK\n'
        '        bigint pension_regime_id FK\n'
        '        decimal last_salary "12,2"\n'
        '        boolean rebel_army_member\n'
        '        date rebel_army_join_date "NULL"\n'
        '        boolean internationalist\n'
        '        bigint filed_by_person_id FK "NULL"\n'
        '        varchar phone "NULL"\n'
        '        varchar popular_council "NULL"\n'
        '        date termination_date "NULL"\n'
        '        varchar current_address "NULL"\n'
        '        bigint residence_province_id FK "NULL"\n'
        '        bigint residence_municipality_id FK "NULL"\n'
        '        varchar bank_account "NULL"\n'
        '        bigint collection_agency_type_id FK "NULL"\n'
        '        bigint collection_agency_id FK "NULL"\n'
        '        bigint approval_legal_basis_id FK "NULL"',
    ),
    # 4.5 ER — the income_concept_records entity joins the diagram.
    (
        '    WORK_CYCLES {\n'
        '        bigint id PK\n'
        '        bigint pension_case_id FK\n'
        '        int planned_days\n'
        '        int actual_days\n'
        '        int cycles_count\n'
        '    }\n'
        '    PENSION_CASE_HISTORIES {',
        '    WORK_CYCLES {\n'
        '        bigint id PK\n'
        '        bigint pension_case_id FK\n'
        '        int planned_days\n'
        '        int actual_days\n'
        '        int cycles_count\n'
        '    }\n'
        '    INCOME_CONCEPT_RECORDS {\n'
        '        bigint id PK\n'
        '        bigint pension_case_id FK\n'
        '        bigint income_concept_id FK\n'
        '        decimal amount "12,2"\n'
        '        decimal applied_percentage "5,2 porciento a aplicar"\n'
        '    }\n'
        '    PENSION_CASE_HISTORIES {',
    ),
    # 4.5 ER — the relation line for the declared income concepts.
    (
        '    PENSION_CASES ||--o{ WORK_CYCLES : "registra"\n'
        '    PENSION_CASES ||--o{ PENSION_CASE_HISTORIES : "audita"\n'
        '```',
        '    PENSION_CASES ||--o{ WORK_CYCLES : "registra"\n'
        '    PENSION_CASES ||--o{ INCOME_CONCEPT_RECORDS : "declara"\n'
        '    PENSION_CASES ||--o{ PENSION_CASE_HISTORIES : "audita"\n'
        '```',
    ),
    # 4.5 note — the composite-FK coherence of the new pairs.
    (
        "`computed_amount` + `calculation_setting_id` congelan el resultado del cálculo al aprobar (RF-CAL-007/008): el expediente resuelto nunca se recalcula.",
        "`computed_amount` + `calculation_setting_id` congelan el resultado del cálculo al aprobar (RF-CAL-007/008): el expediente resuelto nunca se recalcula.\n\nLos pares de residencia (municipio, provincia) y de cobro (agencia, tipo de agencia) del promovente — corrección de usuario de Task 42/SGP-36, documentada con implementación pendiente — viajan coherentes por FK COMPUESTAS: el espejo exacto de RN-04 que las agencias ya usan (el municipio pertenece a la provincia declarada; la agencia de cobro es del tipo declarado).",
    ),
    # 4.6 ER — the PAYMENT_TYPES entity disappears.
    (
        '    PAYMENT_TYPES {\n'
        '        bigint id PK\n'
        '        varchar name "Unico"\n'
        '        varchar description\n'
        '    }\n'
        '    PENSION_PAYMENTS {',
        '    PENSION_PAYMENTS {',
    ),
    # 4.6 ER — payment_type_id leaves the proposal.
    (
        '        bigint payment_type_id FK\n'
        '        smallint period_year',
        '        smallint period_year',
    ),
    # 4.6 ER — the PAYMENT_TYPES relation line disappears.
    (
        '    PAYMENT_TYPES ||--o{ PENSION_PAYMENTS : "clasifica"\n'
        '    BANK_CONTROLS ||--o{ PENSION_PAYMENTS : "liquida"',
        '    BANK_CONTROLS ||--o{ PENSION_PAYMENTS : "liquida"',
    ),
    # 4.6 note — the payments proposal is re-anchored.
    (
        "`pension_payments` es la extensión propuesta (H-16) para el módulo de pagos; se construye solo si el área funcional valida RF-PAG-005.",
        "`pension_payments` es la extensión propuesta (H-16) para el módulo de pagos; se construye solo si el área funcional valida RF-PAG-005. Desde la corrección de usuario de Task 42/SGP-36 (documentada, implementación pendiente) la propuesta queda RE-ANCLADA: sin el catálogo `payment_types`, la forma de pago vive como enum `payment_form` del tipo de agencia y los datos de cobro se capturan como datos bancarios del promovente del expediente (RF-EXP-001).",
    ),
]

MODELO_PATCHES += [
    # 5.1 — agency_types gains payment_form (replacing the payment_types catalog).
    (
        "**`agency_types`** — Tipos de agencia bancaria. `code VARCHAR(4) UNIQUE`, `name VARCHAR(80) UNIQUE`.",
        "**`agency_types`** — Tipos de agencia bancaria. `code VARCHAR(4) UNIQUE`, `name VARCHAR(80) UNIQUE` y, desde la corrección de usuario de Task 42/SGP-36 (documentada, implementación pendiente de validación), `payment_form VARCHAR(30) NULL` — forma de pago del tipo de agencia, enum de dominio con DOS valores literales definidos por el usuario, SIN tilde y tal como los escribió: `tarjeta magnetica` y `Nomina Electronica` (enum PHP `PaymentForm` + CHECK `chk_agency_types_payment_form`, migración prevista `2026_10_03_100100`). El campo SUSTITUYE al catálogo `payment_types`, eliminado por la misma corrección: la forma de cobro pasa a ser un ATRIBUTO del tipo de agencia y viaja por la maquinaria genérica `extraRules` del registry (opcional en el POST/PATCH con 422 sobre cualquier otro valor, devuelta por TODOS los endpoints del recurso); las semillas de referencia (AG/Sucursal, SU/PS/Punto de servicio) quedan SIN valor — pendiente de la decisión del área funcional (P-06).",
    ),
    # 5.2 table — the payment_types row disappears.
    (
        "| `payment_types` | `description VARCHAR(255) NULL` | ABN/Abono bancario, CHQ/Cheque, EFE/Efectivo |\n",
        "",
    ),
    # 5.2 note — the extraRules machinery now also carries the agency-type enum.
    (
        "de modo que editar el sector ya no exige arrastrar `months_per_year`.",
        "de modo que editar el sector ya no exige arrastrar `months_per_year`. Desde la corrección de usuario de Task 42/SGP-36 (documentada, implementación pendiente de validación) el tipo de agencia gana la forma de pago `payment_form` VARCHAR(30) NULL — enum de DOS valores literales del usuario SIN tilde: `tarjeta magnetica` y `Nomina Electronica`, con CHECK `chk_agency_types_payment_form` — servida por la MISMA maquinaria `extraRules` (opcional en POST/PATCH con 422 sobre cualquier otro valor, devuelta por TODOS los endpoints del recurso), mientras el catálogo `payment_types` se ELIMINA completo (migración prevista `2026_10_03_100000`: la tabla desaparece con su modelo, entrada del registry, semillas y enumeración OA; `GET /catalogs/payment-types` responde 404 de catálogo desconocido).",
    ),
    # 5.7 pension_cases — the six new promovente columns.
    (
        "| termination_date | DATE | SÍ | — | Fecha de desvinculación del promovente (Task 38/SGP-32, corrección de usuario): opcional en el wire con regla de forma Y-m-d única (422 con formato inválido; sin sonda semántica), omisión = NULL; migración `2026_10_02_120000` |\n| approval_legal_basis_id |",
        "| termination_date | DATE | SÍ | — | Fecha de desvinculación del promovente (Task 38/SGP-32, corrección de usuario): opcional en el wire con regla de forma Y-m-d única (422 con formato inválido; sin sonda semántica), omisión = NULL; migración `2026_10_02_120000` |\n"
        "| current_address | VARCHAR(255) | SÍ | — | Dirección actual del promovente (Task 42/SGP-36, corrección de usuario — documentada, implementación pendiente de validación): texto libre opcional, paralelo de `people.address`; omisión = NULL |\n"
        "| residence_province_id | BIGINT UNSIGNED | SÍ | FK → provinces | Provincia de residencia del promovente (Task 42): viaja EN PAREJA con el municipio — ambos o ninguno (422 conversacional si llega la mitad) — y sondeada contra el catálogo ACTIVO |\n"
        "| residence_municipality_id | BIGINT UNSIGNED | SÍ | FK → municipalities | Municipio de residencia del promovente (Task 42): debe pertenecer a la provincia declarada — FK compuesta (municipio, provincia) → `municipalities(id, province_id)`, el espejo exacto de RN-04 en las agencias |\n"
        "| bank_account | VARCHAR(30) | SÍ | — | Cuenta bancaria del promovente (Task 42): texto libre opcional con techo, sin formato (sin especificación bancaria — paralelo del par de contacto) |\n"
        "| collection_agency_type_id | BIGINT UNSIGNED | SÍ | FK → agency_types | Tipo de agencia de cobro del promovente (Task 42): viaja EN PAREJA con la agencia; su `payment_form` (tarjeta magnetica / Nomina Electronica) caracteriza el canal de cobro |\n"
        "| collection_agency_id | BIGINT UNSIGNED | SÍ | FK → agencies | Agencia de cobro del promovente (Task 42): debe ser del tipo declarado — FK compuesta (agencia, tipo) → `agencies(id, agency_type_id)` con UNIQUE de respaldo nuevo sobre `agencies` (migración prevista `2026_10_03_100200`) |\n"
        "| approval_legal_basis_id |",
    ),
    # 5.7 income_concept_records — the applied_percentage column.
    (
        "| amount | DECIMAL(12,2) | NO | CHECK ≥ 0 | Valor declarado del concepto (RN-005) |\n| — | — | — | UNIQUE | (`pension_case_id`, `income_concept_id`) — un valor por concepto |",
        "| amount | DECIMAL(12,2) | NO | CHECK ≥ 0 | Valor declarado del concepto (RN-005) |\n"
        "| applied_percentage | DECIMAL(5,2) | NO | CHECK 0-100 | Porciento a aplicar (Task 42/SGP-36, corrección de usuario — documentada, implementación pendiente de validación): el Double declarado aterriza como decimal EXACTO por RN-005; rango 0.00-100.00; migración prevista `2026_10_03_100300` |\n"
        "| — | — | — | UNIQUE | (`pension_case_id`, `income_concept_id`) — un valor por concepto |",
    ),
    # 5.7 income note — the percentage semantics.
    (
        "El par (caso, concepto) se sondea semánticamente antes del insert (422 sobre `income_concept_id`, RN-008) y el catálogo se exige ACTIVO en alta y edición.",
        "El par (caso, concepto) se sondea semánticamente antes del insert (422 sobre `income_concept_id`, RN-008) y el catálogo se exige ACTIVO en alta y edición. Desde la corrección de usuario de Task 42/SGP-36 (documentada, implementación pendiente) cada fila declara además el porciento a aplicar `applied_percentage` DECIMAL(5,2) NOT NULL CHECK 0-100 — el Double del usuario como decimal exacto por RN-005 —, OBLIGATORIO en el alta individual y en el payload anidado de creación y devuelto en las proyecciones.",
    ),
    # 5.7 semantics — the planned migrations join the list.
    (
        "con la nueva expresión): el número del expediente es COMPUESTO",
        "con la nueva expresión) — y las PREVISTAS por la corrección de usuario de Task 42/SGP-36, DOCUMENTADAS con implementación pendiente de validación: `2026_10_03_100000_drop_payment_types_table` (el catálogo tipos de pago desaparece), `2026_10_03_100100_add_payment_form_to_agency_types_table`, `2026_10_03_100200_add_promovente_residence_and_collection_to_pension_cases_table` y `2026_10_03_100300_add_applied_percentage_to_income_concept_records_table` —: el número del expediente es COMPUESTO",
    ),
    # 5.7 semantics — the Task 42 sentence before the authorship note.
    (
        "el 201, el detalle y el listado la devuelven. Autoría del expediente estampada",
        "el 201, el detalle y el listado la devuelven. Desde la corrección de usuario de Task 42/SGP-36 (DOCUMENTADA, implementación pendiente de validación) el expediente gana DOS grupos de datos del promovente: la DIRECCIÓN — `current_address` VARCHAR(255) NULL (dirección actual) y el par de residencia `residence_province_id`/`residence_municipality_id` BIGINT UNSIGNED NULL FK, que viaja EN PAREJA ambos-o-ninguno (422 conversacional si llega la mitad, la misma maquinaria required_with/prohibited_unless del par rebelde) con coherencia territorial por FK compuesta (municipio, provincia) → `municipalities(id, province_id)`, el espejo exacto de RN-04 — y los BANCARIOS — `bank_account` VARCHAR(30) NULL (cuenta bancaria) y el par de cobro `collection_agency_type_id`/`collection_agency_id` BIGINT UNSIGNED NULL FK, también en pareja, con coherencia por FK compuesta (agencia, tipo) → `agencies(id, agency_type_id)` apoyada en un UNIQUE de respaldo nuevo sobre `agencies` (la agencia de cobro es del tipo declarado, cuyo `payment_form` caracteriza el canal) —; los seis son opcionales (omisión = NULL, como el par de contacto), sondeados contra catálogos ACTIVOS como el resto de las referencias, devueltos como ids planos en el 201, el detalle y el listado (como `position_id` y compañía, sin proyección anidada) y PROHIBIDOS en el PUT: pertenecen a la esfera INMUTABLE del promovente (Task 40). El subregistro de concepto de ingreso gana a la vez el porciento a aplicar — `applied_percentage` DECIMAL(5,2) NOT NULL con CHECK 0-100, el Double declarado por el usuario aterrizado como decimal EXACTO por RN-005 —, OBLIGATORIO en el alta individual y en el payload anidado de creación y devuelto en las proyecciones. Autoría del expediente estampada",
    ),
    # 5.8 — the payments proposal loses payment_type_id (title note).
    (
        "**`pension_payments`** (propuesta, H-16) — Pagos periódicos.",
        "**`pension_payments`** (propuesta, H-16) — Pagos periódicos. RE-ANCLADA por la corrección de usuario de Task 42/SGP-36 (documentada, implementación pendiente): sin el catálogo `payment_types`, la forma de pago vive en `agency_types.payment_form` y los datos de cobro se capturan en el expediente (RF-EXP-001).",
    ),
    # 5.8 — the payment_type_id row disappears.
    (
        "| payment_type_id | BIGINT UNSIGNED | NO | FK → payment_types | Tipo de pago |\n",
        "",
    ),
    # 5.8 — the proposal UNIQUE loses the payment type.
    (
        "| — | — | — | UNIQUE | (`pensioner_id`, `period_year`, `period_month`, `payment_type_id`) |",
        "| — | — | — | UNIQUE | (`pensioner_id`, `period_year`, `period_month`) — sin `payment_type_id` desde la Task 42/SGP-36 |",
    ),
    # 6. Enums — the PaymentForm row.
    (
        "| `PaymentStatus` | `pending`, `paid`, `cancelled` | `pension_payments.status` | pending→paid/cancelled (propuesta) |",
        "| `PaymentStatus` | `pending`, `paid`, `cancelled` | `pension_payments.status` | pending→paid/cancelled (propuesta) |\n| `PaymentForm` | `tarjeta magnetica`, `Nomina Electronica` | `agency_types.payment_form` (CHECK) | — (Task 42/SGP-36, corrección de usuario: valores literales SIN tilde tal como los escribió el usuario; documentada, implementación pendiente de validación) |",
    ),
    # 7. Integrity — the new composite-FK pairs.
    (
        "- **Coherencia geográfica** (RN-004): validada en dominio; documentada como restricción de aplicación deliberada (la FK a municipio ya restringe el universo válido).",
        "- **Coherencia geográfica** (RN-004): validada en dominio; documentada como restricción de aplicación deliberada (la FK a municipio ya restringe el universo válido).\n- **Coherencia de residencia y cobro del promovente** (Task 42/SGP-36, documentada — implementación pendiente de validación): los pares (municipio, provincia) de residencia y (agencia, tipo de agencia) de cobro del expediente se garantizan en BD con FK compuestas — el espejo exacto del patrón RN-04 de las agencias, con UNIQUE de respaldo nuevo sobre `agencies(id, agency_type_id)` — y el «ambos o ninguno» del wire se decide en la capa de aplicación (required_with/prohibited_unless).",
    ),
    # 8. Indexes — the proposal UNIQUE without the payment type.
    (
        "UNIQUE (`pensioner_id`, `period_year`, `period_month`, `payment_type_id`) cubre rangos",
        "UNIQUE (`pensioner_id`, `period_year`, `period_month`) cubre rangos",
    ),
    # 10. Seeders — the CatalogsSeeder row loses the payment types.
    (
        "tipos de agencia, tipos de entidad/oficina, tipos de pago, conceptos de ingreso, cargos base",
        "tipos de agencia (con `payment_form` SIN semilla de referencia hasta la decisión del área funcional, Task 42), tipos de entidad/oficina, conceptos de ingreso, cargos base — los tipos de pago quedaron FUERA: catálogo eliminado por la Task 42/SGP-36",
    ),
    # 12. Changelog — the 1.26 row.
    (
        "— semántica 5.7 y lista de migraciones actualizadas | Arq. Backend |",
        "— semántica 5.7 y lista de migraciones actualizadas | Arq. Backend |\n| 1.26 | 2026-10-03 | Corrección de usuario (Task 42/SGP-36) — AJUSTE DE DOCUMENTACIÓN, implementación pendiente de validación, sin cambios de código: el catálogo `payment_types` se ELIMINA (fila del glosario, entrada 5.2, entidades y relaciones de los ER 4.1/4.6 y semillas fuera; la propuesta `pension_payments` pierde su FK a tipos de pago y queda re-anclada en la forma de pago del tipo de agencia); `agency_types` gana la forma de pago `payment_form` VARCHAR(30) NULL — enum de DOS valores literales del usuario SIN tilde: `tarjeta magnetica` y `Nomina Electronica` (enum PHP `PaymentForm` + CHECK, migración prevista `2026_10_03_100100`) —; el expediente gana los datos de DIRECCIÓN del promovente (`current_address` + par de residencia con FK compuesta RN-04) y los BANCARIOS (`bank_account` + par de cobro con FK compuesta sobre `agencies(id, agency_type_id)` y UNIQUE de respaldo, migración prevista `2026_10_03_100200`), opcionales y en pares ambos-o-ninguno, prohibidos en el PUT; y el subregistro de concepto de ingreso gana el porciento a aplicar `applied_percentage` DECIMAL(5,2) NOT NULL CHECK 0-100 (el Double del usuario como decimal exacto RN-005, migración prevista `2026_10_03_100300`) — entrada 5.7, ER 4.2/4.5/4.6, enums, integridad, índices y semillas actualizados | Arq. Backend |",
    ),
]

# ---------------------------------------------------------------------------
# Requisitos funcionales.md
# ---------------------------------------------------------------------------
REQUISITOS_PATCHES: list[tuple[str, str]] = [
    # RF-CAT-001 — the live enumeration drops the payment types.
    (
        "regímenes de pensión, tipos de pago y conceptos de ingreso.\n- [ ] Alta, edición, listado paginado",
        "regímenes de pensión y conceptos de ingreso (los tipos de pago DEJARON de ser catálogo por la corrección de usuario de Task 42/SGP-36 — su rol pasó a `agency_types.payment_form`, ver ítem marcado).\n- [ ] Alta, edición, listado paginado",
    ),
    # RF-CAT-001 — the new marked item, after the Task 38 one.
    (
        "de modo que editar el sector ya no exige arrastrar `months_per_year`.\n- [ ] La eliminación es lógica",
        "de modo que editar el sector ya no exige arrastrar `months_per_year`.\n- [ ] Forma de pago del tipo de agencia y ELIMINACIÓN del catálogo Tipo de pago (corrección de usuario, Task 42/SGP-36 — DOCUMENTADA, implementación pendiente de validación): el catálogo `payment_types` desaparece COMPLETO (migración prevista `2026_10_03_100000`: tabla, modelo, entrada del registry, semillas y enumeración OA fuera; `GET /catalogs/payment-types` responde 404 de catálogo desconocido) y su rol lo asume `agency_types.payment_form` — enum de DOS valores literales definidos por el usuario, SIN tilde y tal como los escribió: `tarjeta magnetica` y `Nomina Electronica` —, opcional en el alta/edición del tipo de agencia (422 con cualquier otro valor), con CHECK en BD, devuelto por TODOS los endpoints del catálogo genérico y sembrado SIN valor de referencia hasta la decisión del área funcional (P-06); migración prevista `2026_10_03_100100`.\n- [ ] La eliminación es lógica",
    ),
    # RF-CAT-004 — the seeder list drops the payment types.
    (
        "(razas, niveles educacionales, categorías ocupacionales y científicas, tipos de pensión, regímenes, tipos de pago, conceptos de ingreso).",
        "(razas, niveles educacionales, categorías ocupacionales y científicas, tipos de pensión, regímenes y conceptos de ingreso; los tipos de pago quedaron fuera por la Task 42/SGP-36).",
    ),
    # RF-EXP-001 — the new promovente data groups, after the Task 38 item.
    (
        "y el 201, el detalle y el listado la devuelven.\n- [x] Eliminación LÓGICA del expediente",
        "y el 201, el detalle y el listado la devuelven.\n"
        "- [ ] Datos de DIRECCIÓN y BANCARIOS del promovente (corrección de usuario, Task 42/SGP-36 — DOCUMENTADA, implementación pendiente de validación): DOS grupos opcionales del expediente — (1) DIRECCIÓN: `current_address` VARCHAR(255) NULL (dirección actual) y el par `residence_province_id`/`residence_municipality_id` FK a provincias/municipios que viaja EN PAREJA (ambos o ninguno: 422 conversacional si llega la mitad; el municipio debe pertenecer a la provincia declarada, RN-04 por FK compuesta); (2) BANCARIOS: `bank_account` VARCHAR(30) NULL (cuenta bancaria) y el par `collection_agency_type_id`/`collection_agency_id` FK a tipos de agencia/agencias también en pareja (la agencia debe ser del tipo declarado; la forma de pago del TIPO — `payment_form` — caracteriza el canal de cobro) —; todos con sondas de catálogo ACTIVO (desactivado = 422), omisión = NULL, devueltos en 201/detalle/listado y PROHIBIDOS en el PUT (la esfera del promovente es inmutable, Task 40); migración prevista `2026_10_03_100200`.\n"
        "- [x] Eliminación LÓGICA del expediente",
    ),
    # RF-EXP-002b — the applied percentage item.
    (
        "Los conceptos viajan anidados en la creación del expediente (todo o nada) y con endpoints propios de alta/baja.\n\n**RF-EXP-003",
        "Los conceptos viajan anidados en la creación del expediente (todo o nada) y con endpoints propios de alta/baja.\n- [ ] Porciento a aplicar (corrección de usuario, Task 42/SGP-36 — DOCUMENTADA, implementación pendiente de validación): cada concepto declarado lleva `applied_percentage` DECIMAL(5,2) — el Double declarado aterriza como decimal EXACTO por RN-005 —, OBLIGATORIO en el alta individual y en el payload anidado, con rango 0.00-100.00 respaldado por CHECK en BD y devuelto en las proyecciones; migración prevista `2026_10_03_100300`.\n\n**RF-EXP-003",
    ),
    # RF-PAG-003 — re-anchored to the live model.
    (
        "**RF-PAG-003 (M) — Catálogos del pago (MO)**\n- [ ] Gestión de tipos de pago y conceptos de ingreso conforme al modelo original.",
        "**RF-PAG-003 (M) — Catálogos del pago (MO)**\n- [ ] Gestión de los catálogos del pago conforme al modelo VIGENTE: los conceptos de ingreso por el recurso genérico de catálogos y la forma de cobro como ATRIBUTO del tipo de agencia (`payment_form`: `tarjeta magnetica` / `Nomina Electronica`) — corrección de usuario de Task 42/SGP-36 (documentada, implementación pendiente) que ELIMINA el catálogo tipos de pago; su gestión vive en RF-CAT-001 y los datos de cobro del promovente en RF-EXP-001.",
    ),
    # 8. Traceability — the RF-PAG row without payment_types.
    (
        "| RF-PAG-* | Payments | `bank_controls`, `agencies`, `payment_types`, `pension_payments` (propuesta) |",
        "| RF-PAG-* | Payments | `bank_controls`, `agencies`, `pension_payments` (propuesta) — la forma de pago vive en `agency_types.payment_form` (Task 42/SGP-36) |",
    ),
]

# ---------------------------------------------------------------------------
# Diseño de arquitectura.md
# ---------------------------------------------------------------------------
ARQUITECTURA_PATCHES: list[tuple[str, str]] = [
    # ADR-15 narrative — the catalog counts and the new enum.
    (
        "Los 18 catálogos de la Fase 1 aterrizaron con un patrón de registry:",
        "Los 18 catálogos de la Fase 1 aterrizaron con un patrón de registry (17 en la superficie vigente: la corrección de usuario de Task 42/SGP-36, documentada con implementación pendiente, ELIMINA el catálogo tipos de pago):",
    ),
    (
        "Un solo par de servicio/repositorio con contrato (ADR-11/12) cubre los 16 catálogos uniformes tras `/api/v1/catalogs/{type}` — y desde la corrección de usuario de Task 31 la bandera `hasCode` es `true` para los dieciséis, así TODOS los listados devuelven el campo `code` —;",
        "Un solo par de servicio/repositorio con contrato (ADR-11/12) cubre los 15 catálogos uniformes tras `/api/v1/catalogs/{type}` (16 hasta la Task 42) — y desde la corrección de usuario de Task 31 la bandera `hasCode` es `true` para todos, así TODOS los listados devuelven el campo `code` —, con la forma de pago del tipo de agencia (`payment_form`, enum de dos valores literales del usuario SIN tilde: `tarjeta magnetica` y `Nomina Electronica`) servida por la MISMA maquinaria `extraRules` desde la Task 42 (documentada, implementación pendiente) —;",
    ),
    (
        "Los 18 modelos extienden `CatalogModel`",
        "Los 17 modelos extienden `CatalogModel`",
    ),
    # Endpoints table — the generic catalog row.
    (
        "CRUD de catálogos uniformes (recurso genérico ADR-15: 16 tipos, todos con `code` desde Task 31);",
        "CRUD de catálogos uniformes (recurso genérico ADR-15: 15 tipos desde la Task 42 — 16 hasta entonces —, todos con `code` desde Task 31);",
    ),
    (
        "anclado campo a campo por el contrato de ApiDocsTest |",
        "anclado campo a campo por el contrato de ApiDocsTest; Task 42/SGP-36 (corrección de usuario, DOCUMENTADA — implementación pendiente de validación): `payment-types` FUERA del recurso (migración prevista `2026_10_03_100000` que borra la tabla: `GET /catalogs/payment-types` → 404 de catálogo desconocido) y `agency-types` gana `payment_form` — enum de DOS valores literales del usuario SIN tilde: `tarjeta magnetica` y `Nomina Electronica` — opcional en POST/PATCH (422 con cualquier otro valor, CHECK `chk_agency_types_payment_form` en BD como última línea) y devuelto por TODOS los endpoints, reconocido en el Schema de Entrada de la spec (migración prevista `2026_10_03_100100`) |",
    ),
    # Endpoints table — the case store row gains the promovente groups.
    (
        "devuelta en 201/detalle/listado), subregistros declarados en la MISMA transacción",
        "devuelta en 201/detalle/listado), datos de DIRECCIÓN y BANCARIOS del promovente (Task 42/SGP-36, DOCUMENTADA — implementación pendiente de validación: `current_address` VARCHAR(255) NULL más el par de residencia `residence_province_id`/`residence_municipality_id` FK y el par bancario `bank_account` VARCHAR(30) NULL más `collection_agency_type_id`/`collection_agency_id` FK, los pares EN PAREJA ambos-o-ninguno con coherencia por FK compuestas — municipio de la provincia declarada, agencia del tipo declarado — y sondas de catálogo activo; migración prevista `2026_10_03_100200`), todos opcionales y devueltos como ids planos, subregistros declarados en la MISMA transacción",
    ),
    # Endpoints table — the PUT/DELETE row extends the immutable sphere.
    (
        "todo campo de la esfera de la persona (applicant, filer, par de Ejército Rebelde, internacionalista, par de contacto, fecha de desvinculación) y los de ciclo de vida",
        "todo campo de la esfera de la persona (applicant, filer, par de Ejército Rebelde, internacionalista, par de contacto, fecha de desvinculación y, desde la Task 42/SGP-36 documentada, dirección y datos bancarios del promovente) y los de ciclo de vida",
    ),
    # Endpoints table — the subrecords row gains the percentage.
    (
        "con catálogo activo sondeado y DECIMAL(12,2) no negativo; forma de declaración",
        "con catálogo activo sondeado y DECIMAL(12,2) no negativo — y, desde la Task 42/SGP-36 (documentada, implementación pendiente), el porciento a aplicar `applied_percentage` DECIMAL(5,2) OBLIGATORIO con CHECK 0-100 (el Double del usuario como decimal exacto RN-005) en el alta individual y en el payload anidado (migración prevista `2026_10_03_100300`) —; forma de declaración",
    ),
    # ADR-15 row — the amendment.
    (
        "agregar un catálogo es una entrada de datos, no código nuevo; 18 tablas comparten 6 piezas de contratos |",
        "agregar un catálogo es una entrada de datos, no código nuevo; 18 tablas comparten 6 piezas de contratos (17 desde la Task 42/SGP-36, documentada: `payment-types` eliminado — 15 uniformes — y `agency-types` gana `payment_form`, enum `tarjeta magnetica`/`Nomina Electronica`, por la maquinaria `extraRules`) |",
    ),
    # Changelog — the 1.34 row.
    (
        "fumiga de expedientes ampliada a 61 comprobaciones TODO OK | Arq. Backend |",
        "fumiga de expedientes ampliada a 61 comprobaciones TODO OK | Arq. Backend |\n| 1.34 | 2026-10-03 | Corrección de usuario (Task 42/SGP-36) — AJUSTE DE DOCUMENTACIÓN, implementación pendiente de validación, sin cambios de código: `payment-types` sale del recurso genérico de catálogos (15 uniformes; `GET /catalogs/payment-types` → 404 de catálogo desconocido) y `agency-types` gana `payment_form` (enum de dos valores literales del usuario SIN tilde: `tarjeta magnetica` / `Nomina Electronica`) por la maquinaria `extraRules`; el alta del expediente gana los datos de dirección y bancarios del promovente (opcionales, en pares coherentes por FK compuestas — residencia RN-04, cobro sobre `agencies(id, agency_type_id)` —, prohibidos en el PUT por la inmutabilidad del promovente) y el subregistro de concepto de ingreso gana `applied_percentage` DECIMAL(5,2) CHECK 0-100 (Double → decimal exacto RN-005); filas de endpoints, narrativa ADR-15 y spec OpenAPI (prevista 1.4.0) documentadas — la suite permanece en 1113/3829 sobre main | Arq. Backend |",
    ),
]

# ---------------------------------------------------------------------------
# 04_Plan_de_desarrollo.md
# ---------------------------------------------------------------------------
PLAN_PATCHES: list[tuple[str, str]] = [
    # S5.2 — the case creation item documents the new promovente groups.
    (
        "actor sin oficina con página vacía) TODO OK",
        "actor sin oficina con página vacía) TODO OK ✅ 2026-10-03 DOCUMENTADA por la corrección de usuario de Task 42/SGP-36 (fase de AJUSTE DE DOCUMENTACIÓN — implementación pendiente de validación, sin cambios de código): el expediente gana los datos de DIRECCIÓN del promovente (`current_address` + par de residencia `residence_province_id`/`residence_municipality_id` con coherencia RN-04 por FK compuesta) y los BANCARIOS (`bank_account` + par de cobro `collection_agency_type_id`/`collection_agency_id` con FK compuesta sobre `agencies(id, agency_type_id)`), todos opcionales, en pares ambos-o-ninguno y PROHIBIDOS en el PUT (esfera inmutable del promovente); migración prevista `2026_10_03_100200`",
    ),
    # Subrecords item — the applied percentage.
    (
        "mismo comportamiento con el wire y el OA en inglés",
        "mismo comportamiento con el wire y el OA en inglés ✅ 2026-10-03 DOCUMENTADA por la corrección de usuario de Task 42/SGP-36 (implementación pendiente de validación): el alta de conceptos de ingreso — individual y anidada — gana el porciento a aplicar `applied_percentage` DECIMAL(5,2) NOT NULL con CHECK 0-100 (el Double del usuario como decimal exacto RN-005), devuelto en las proyecciones; migración prevista `2026_10_03_100300`",
    ),
    # Sprint 10 — the payment catalogs item, re-anchored.
    (
        "- [ ] Catálogos del pago (RF-PAG-003): tipos de pago, formas, estados según modelo de datos.",
        "- [ ] Catálogos del pago (RF-PAG-003): formas y estados según el modelo VIGENTE — el catálogo tipos de pago quedó ELIMINADO por la corrección de usuario de Task 42/SGP-36 (documentada, implementación pendiente): la forma de pago (`tarjeta magnetica`/`Nomina Electronica`) vive como `payment_form` del tipo de agencia ya en la superficie de la Fase 1 y los estados de pago permanecen como propuesta (H-16) del módulo.",
    ),
    # Changelog — the 1.25 row.
    (
        "fumiga de expedientes ampliada a 61 comprobaciones TODO OK | Arq. Backend |",
        "fumiga de expedientes ampliada a 61 comprobaciones TODO OK | Arq. Backend |\n| 1.25 | 2026-10-03 | Corrección de usuario (Task 42/SGP-36; ítems S5.2, subregistros del Sprint 5 y S10.1 documentados): AJUSTE DE DOCUMENTACIÓN con implementación PENDIENTE DE VALIDACIÓN — el catálogo tipos de pago se elimina (su rol pasa a `agency_types.payment_form`, enum de dos valores literales del usuario SIN tilde: `tarjeta magnetica`/`Nomina Electronica`); el expediente gana los datos de dirección y bancarios del promovente (opcionales, en pares coherentes por FK compuestas, prohibidos en el PUT); y el subregistro de concepto de ingreso gana `applied_percentage` DECIMAL(5,2) CHECK 0-100 (Double → decimal exacto RN-005) — sin cambios de código en esta fase: la suite permanece 1113/3829 sobre main | Arq. Backend |",
    ),
]

# ---------------------------------------------------------------------------
# Driver
# ---------------------------------------------------------------------------


def main() -> int:
    targets: list[tuple[Path, list[tuple[str, str]]]] = [
        (MODELO, MODELO_PATCHES),
        (REQUISITOS, REQUISITOS_PATCHES),
        (ARQUITECTURA, ARQUITECTURA_PATCHES),
        (PLAN, PLAN_PATCHES),
    ]

    for path, edits in targets:
        text = path.read_text(encoding="utf-8")
        for old, new in edits:
            count = text.count(old)
            assert count == 1, (
                f"{path.name}: anchor must be unique, found {count}: {old[:90]!r}"
            )
            text = text.replace(old, new, 1)
        path.write_text(text, encoding="utf-8")
        print(f"{path.name}: {len(edits)} parches aplicados")

    total = sum(len(edits) for _, edits in targets)
    print(f"TOTAL: {total} parches en {len(targets)} documentos")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
