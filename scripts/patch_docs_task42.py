#!/usr/bin/env python3
"""Patch the four SGP documents for Task 42 / SGP-36 (user correction,
DOCUMENTATION-ONLY delivery: the docs are adjusted and the implementation
is PREPARED, but NO code is touched until the user validates).

Four model adjustments, refined by the user's answers:
1. Eliminate the Tipo de pago model (payment_types).
2. agency_types gains payment_form enum — lowercase unified values
   'tarjeta magnetica' / 'nomina electronica', required with DEFAULT
   'tarjeta magnetica'.
3. pension_cases gains the promovente residence + collection group
   (current_address, residence_province_id, residence_municipality_id,
   bank_account, collection_agency_type_id, collection_agency_id) —
   all required EXCEPT bank_account, which is required when the payment
   form of the collection agency type is 'tarjeta magnetica'; the whole
   group is EDITABLE via PUT.
4. income_concept_records gains applied_percent — Double, required,
   range 0-100, 2 decimals.

Anchored, asserted, idempotent patches (the Task 40/41 pattern).
"""

from pathlib import Path

ROOT = Path("/home/z/my-project/download")

PEND = ("corrección de usuario, Task 42/SGP-36 — documentada, implementación "
        "pendiente de la validación del usuario")

REQUISITOS: list[tuple[str, str]] = [
    # RF-CAT-001: the enumeration drops tipos de pago.
    (
        "razas, cargos, regímenes de pensión, tipos de pago y conceptos de ingreso.\n"
        "- [ ] Alta, edición, listado paginado y detalle por catálogo con los campos del modelo original.",
        "razas, cargos, regímenes de pensión y conceptos de ingreso.\n"
        "- [ ] Alta, edición, listado paginado y detalle por catálogo con los campos del modelo original.",
    ),
    # RF-CAT-001: two new items after the Task 38 item.
    (
        "de modo que editar el sector ya no exige arrastrar `months_per_year`.\n"
        "- [ ] La eliminación es lógica (desactivación) y queda bloqueada cuando existan referencias activas.",
        "de modo que editar el sector ya no exige arrastrar `months_per_year`.\n"
        "- [ ] ELIMINACIÓN del catálogo Tipo de pago (" + PEND + "): la tabla `payment_types`, el modelo "
        "`PaymentType`, su seeder y la superficie `payment-types` del recurso genérico dejan de existir — "
        "la forma de pago del cobro pasa a vivir EN el tipo de agencia (`payment_form`, ítem siguiente) —; "
        "nadie lo referencia (la propuesta `pension_payments` de RF-PAG-005 no está construida), de modo que "
        "la baja es una eliminación limpia del modelo y de la superficie de catálogos uniformes, que quedan "
        "en quince.\n"
        "- [ ] Forma de pago del tipo de agencia (" + PEND + "): `agency_types.payment_form` — enum de DOS "
        "valores en MINÚSCULAS unificadas, sin tildes, tal como los fijó el usuario: `tarjeta magnetica` y "
        "`nomina electronica` —, OBLIGATORIO con DEFAULT `tarjeta magnetica` (NOT NULL + CHECK en BD; la "
        "omisión del alta cae en el default — el patrón `deceased_person` de la Task 38 — y cualquier otro "
        "valor responde 422) y editable en PATCH (regla relajada a `sometimes` como el resto de las columnas "
        "propias); devuelto por TODOS los endpoints del catálogo `agency-types` (201, detalle, listado y "
        "PATCH) y sembrado explícito en el seeder; decide la obligatoriedad de la cuenta bancaria del cobro "
        "del expediente (RF-EXP-001).\n"
        "- [ ] La eliminación es lógica (desactivación) y queda bloqueada cuando existan referencias activas.",
    ),
    # RF-CAT-004: seeders drop payment types, agency types gain payment_form.
    (
        "- [ ] Seeders de organismos de la Administración Central del Estado y catálogos clasificatorios de "
        "referencia (razas, niveles educacionales, categorías ocupacionales y científicas, tipos de pensión, "
        "regímenes, tipos de pago, conceptos de ingreso).",
        "- [ ] Seeders de organismos de la Administración Central del Estado y catálogos clasificatorios de "
        "referencia (razas, niveles educacionales, categorías ocupacionales y científicas, tipos de pensión, "
        "regímenes y conceptos de ingreso; los tipos de pago dejan de sembrarse por la eliminación de la "
        "Task 42/SGP-36 y los tipos de agencia siembran además su `payment_form`).",
    ),
    # RF-EXP-001: the promovente residence + collection item (after the
    # Task 40 lifecycle items, before the Rebel Army classification).
    (
        "y los campos de ciclo de vida (`office_id` regla 0, `number`, `status`) también 422.\n"
        "- [ ] Clasificación de Ejército Rebelde:",
        "y los campos de ciclo de vida (`office_id` regla 0, `number`, `status`) también 422.\n"
        "- [ ] Domicilio y cobro del promovente (" + PEND + "): el expediente gana DOS grupos de datos del "
        "promovente, todos obligatorios SALVO la cuenta bancaria — (1) DOMICILIO: `current_address` "
        "VARCHAR(255) (dirección actual), `residence_province_id` FK → `provinces` (provincia de residencia) "
        "y `residence_municipality_id` FK → `municipalities` (municipio de residencia), con la coherencia "
        "RN-004 sondeada: el municipio debe pertenecer a la provincia declarada (422; ver P-09 para el "
        "municipio especial) —; (2) COBRO: `collection_agency_type_id` FK → `agency_types` (tipo de agencia "
        "de cobro; sonda ACTIVA), `collection_agency_id` FK → `agencies` (agencia de cobro; sonda ACTIVA y "
        "de-ese-tipo: la agencia debe pertenecer al tipo declarado, 422) y `bank_account` VARCHAR(34) NULL — "
        "OBLIGATORIA CONDICIONADA: exigida (422 sobre `bank_account`) cuando la forma de pago del tipo de "
        "agencia de cobro es `tarjeta magnetica`, OPCIONAL con `nomina electronica` (omisión = NULL; "
        "proveerla se permite) —. El alta los recibe, el 201/detalle/listado los devuelven con sus "
        "proyecciones (provincia, municipio, tipo de agencia con su forma de pago y agencia) y el PUT los "
        "admite como campos EDITABLES (decisión explícita del usuario: pueden modificarse) con probes espejo "
        "del alta y la exigencia condicional de la cuenta re-evaluada contra el estado RESULTANTE.\n"
        "- [ ] Clasificación de Ejército Rebelde:",
    ),
    # RF-EXP-002b: applied_percent bullet.
    (
        "- [ ] Cada expediente declara conceptos de ingreso del catálogo con un VALOR decimal exacto "
        "(dos decimales, no negativo).",
        "- [ ] Cada expediente declara conceptos de ingreso del catálogo con un VALOR decimal exacto "
        "(dos decimales, no negativo).\n"
        "- [ ] Porciento a aplicar (" + PEND + "): cada declaración lleva `applied_percent` DECIMAL(5,2) — "
        "Double OBLIGATORIO en el wire con rango 0–100 y 2 decimales exactos (422 si se omite, si queda "
        "fuera del rango o si trae más de dos decimales; CHECK de BD como última línea; DEFAULT 0.00 solo "
        "para escrituras fuera del wire) —; viaja POR FILA en el alta anidada, en el endpoint propio de "
        "alta y en las respuestas.",
    ),
    # H-16 hazard row.
    (
        "| H-16 | Falta entidad de pago periódico: `Tipo de pago` y `Conceptos de ingreso` no tienen "
        "transacciones asociadas | Media | Proponer `pension_payments` (marcado como propuesta, RF-PAG-005, "
        "pendiente de validación) |",
        "| H-16 | Falta entidad de pago periódico: los conceptos de ingreso no tienen transacciones "
        "asociadas (el catálogo `Tipo de pago` fue ELIMINADO por la corrección de usuario de la "
        "Task 42/SGP-36 — documentada, pendiente de implementación: la forma de pago vive en el tipo de "
        "agencia) | Media | Proponer `pension_payments` (marcado como propuesta, RF-PAG-005, pendiente de "
        "validación) |",
    ),
    # RF-PAG-003.
    (
        "- [ ] Gestión de tipos de pago y conceptos de ingreso conforme al modelo original.",
        "- [ ] Gestión de conceptos de ingreso conforme al modelo original. Corrección de usuario "
        "(Task 42/SGP-36 — documentada, implementación pendiente de validación): el catálogo `Tipo de pago` "
        "se ELIMINA del modelo — la forma de pago del cobro vive en el tipo de agencia "
        "(`agency_types.payment_form`) y ya no existe una superficie de catálogo que gestionar para el "
        "pago.",
    ),
    # Traceability matrix: RF-PAG row.
    (
        "| RF-PAG-* | Payments | `bank_controls`, `agencies`, `payment_types`, `pension_payments` (propuesta) |",
        "| RF-PAG-* | Payments | `bank_controls`, `agencies`, `pension_payments` (propuesta; sin "
        "`payment_types`, eliminado por la Task 42) |",
    ),
    # Open question P-09 (special municipality vs required residence province).
    (
        "| P-08 | Algoritmo oficial del dígito verificador del carnet de identidad (posición 11); no existe "
        "fuente pública verificable | Validación de identidad en People (RN-001) | Validación estructural "
        "corregida 2026-09-30 (ADR-30: 11 dígitos, mes/día y sexo por paridad del dígito 10 — año y "
        "consecutivo sin validar); política de checksum intercambiable cuando el Ministerio confirme la "
        "regla |",
        "| P-08 | Algoritmo oficial del dígito verificador del carnet de identidad (posición 11); no existe "
        "fuente pública verificable | Validación de identidad en People (RN-001) | Validación estructural "
        "corregida 2026-09-30 (ADR-30: 11 dígitos, mes/día y sexo por paridad del dígito 10 — año y "
        "consecutivo sin validar); política de checksum intercambiable cuando el Ministerio confirme la "
        "regla |\n"
        "| P-09 | Residencia en el municipio especial Isla de la Juventud: el municipio no pertenece a "
        "provincia alguna, pero la corrección de la Task 42 exige provincia y municipio de residencia "
        "obligatorios y coherentes (RN-004) | Expediente: promoventes residentes en la Isla de la Juventud "
        "no podrían declarar una residencia válida | Documentado con la misma regla de "
        "entidades/oficinas/agencias (RN-004 plena: el municipio especial queda fuera del dominio de "
        "residencia); decisión a validar junto con la implementación (alternativa: provincia de residencia "
        "nullable solo para el municipio especial) |",
    ),
]

MODELO: list[tuple[str, str]] = [
    # Glossary: drop the Tipo de pago row.
    (
        "| Régimen de pensión | `pension_regimes` | `PensionRegime` |\n"
        "| Tipo de pago | `payment_types` | `PaymentType` |\n"
        "| Concepto de ingreso | `income_concepts` | `IncomeConcept` |",
        "| Régimen de pensión | `pension_regimes` | `PensionRegime` |\n"
        "| Concepto de ingreso | `income_concepts` | `IncomeConcept` |",
    ),
    # 4.1 panorama: the PAYMENT_TYPES relation leaves with the entity.
    (
        "    AGENCIES ||--o{ BANK_CONTROLS : \"gestiona\"\n"
        "    PENSIONERS ||--o{ PENSION_PAYMENTS : \"recibe\"\n"
        "    PAYMENT_TYPES ||--o{ PENSION_PAYMENTS : \"clasifica\"\n"
        "    PEOPLE ||--o| USERS : \"vinculada\"",
        "    AGENCIES ||--o{ BANK_CONTROLS : \"gestiona\"\n"
        "    PENSIONERS ||--o{ PENSION_PAYMENTS : \"recibe\"\n"
        "    PEOPLE ||--o| USERS : \"vinculada\"",
    ),
    # 4.2 ER: AGENCY_TYPES gains payment_form.
    (
        "    AGENCY_TYPES {\n"
        "        bigint id PK\n"
        "        varchar code \"Unico\"\n"
        "        varchar name\n"
        "    }",
        "    AGENCY_TYPES {\n"
        "        bigint id PK\n"
        "        varchar code \"Unico\"\n"
        "        varchar name\n"
        "        varchar payment_form \"tarjeta magnetica | nomina electronica, DEFAULT tarjeta magnetica\"\n"
        "    }",
    ),
    # 4.6 ER: the PAYMENT_TYPES block and its FK leave.
    (
        "    PAYMENT_TYPES {\n"
        "        bigint id PK\n"
        "        varchar name \"Unico\"\n"
        "        varchar description\n"
        "    }\n"
        "    PENSION_PAYMENTS {\n"
        "        bigint id PK\n"
        "        bigint pensioner_id FK\n"
        "        bigint payment_type_id FK\n",
        "    PENSION_PAYMENTS {\n"
        "        bigint id PK\n"
        "        bigint pensioner_id FK\n",
    ),
    # 4.6 ER: the PAYMENT_TYPES relation leaves.
    (
        "    PENSIONERS ||--o{ PENSION_PAYMENTS : \"recibe\"\n"
        "    PAYMENT_TYPES ||--o{ PENSION_PAYMENTS : \"clasifica\"\n"
        "    BANK_CONTROLS ||--o{ PENSION_PAYMENTS : \"liquida\"",
        "    PENSIONERS ||--o{ PENSION_PAYMENTS : \"recibe\"\n"
        "    BANK_CONTROLS ||--o{ PENSION_PAYMENTS : \"liquida\"",
    ),
    # 5.1: agency_types grows from an inline summary to a column table.
    (
        "**`agency_types`** — Tipos de agencia bancaria. `code VARCHAR(4) UNIQUE`, `name VARCHAR(80) UNIQUE`.\n",
        "**`agency_types`** — Tipos de agencia bancaria.\n"
        "\n"
        "| Columna | Tipo | Nulo | Clave | Descripción |\n"
        "|---|---|---|---|---|\n"
        "| code | VARCHAR(4) | NO | UNIQUE | Código del tipo de agencia |\n"
        "| name | VARCHAR(80) | NO | UNIQUE | Nombre |\n"
        "| payment_form | VARCHAR(20) | NO | CHECK, DEFAULT 'tarjeta magnetica' | Forma de pago del cobro "
        "(Task 42/SGP-36, corrección de usuario — documentada, implementación pendiente de validación): "
        "enum `tarjeta magnetica` / `nomina electronica` — valores en MINÚSCULAS unificadas, sin tildes, "
        "tal como los fijó el usuario —, OBLIGATORIA con DEFAULT `tarjeta magnetica` (la omisión del alta "
        "del catálogo cae en el default; el patrón `deceased_person` de la Task 38) y PATCH relajado a "
        "`sometimes`; servida por la maquinaria `extraRules` del recurso genérico y condiciona la "
        "obligatoriedad de la cuenta bancaria del cobro del expediente (sección 5.7) |\n"
        "\n"
        "CHECK: `payment_form IN ('tarjeta magnetica', 'nomina electronica')`.\n",
    ),
    # 5.2: the payment_types row leaves the classifier table.
    (
        "| `payment_types` | `description VARCHAR(255) NULL` | ABN/Abono bancario, CHQ/Cheque, EFE/Efectivo |\n",
        "",
    ),
    # 5.2: closing paragraph documents the elimination + the new column.
    (
        "ambos devueltos por TODOS los endpoints del recurso genérico, y el PATCH relaja a `sometimes` las "
        "reglas `required` de las columnas propias de modo que editar el sector ya no exige arrastrar "
        "`months_per_year`.",
        "ambos devueltos por TODOS los endpoints del recurso genérico, y el PATCH relaja a `sometimes` las "
        "reglas `required` de las columnas propias de modo que editar el sector ya no exige arrastrar "
        "`months_per_year`. Desde la Task 42 (corrección de usuario, SGP-36 — documentada, implementación "
        "pendiente de validación) el catálogo `payment_types` queda ELIMINADO — tabla, modelo `PaymentType`, "
        "seeder y superficie `payment-types` del recurso genérico dejan de existir; quedan QUINCE catálogos "
        "uniformes — y en su lugar el TIPO DE AGENCIA gana la columna propia `payment_form` (sección 5.1) "
        "servida por la misma maquinaria `extraRules`: devuelta por TODOS los endpoints de `agency-types`, "
        "omisión del alta → DEFAULT `tarjeta magnetica` y PATCH `sometimes`.",
    ),
    # 5.7 pension_cases: six new rows after termination_date.
    (
        "| termination_date | DATE | SÍ | — | Fecha de desvinculación del promovente (Task 38/SGP-32, "
        "corrección de usuario): opcional en el wire con regla de forma Y-m-d única (422 con formato "
        "inválido; sin sonda semántica), omisión = NULL; migración `2026_10_02_120000` |\n",
        "| termination_date | DATE | SÍ | — | Fecha de desvinculación del promovente (Task 38/SGP-32, "
        "corrección de usuario): opcional en el wire con regla de forma Y-m-d única (422 con formato "
        "inválido; sin sonda semántica), omisión = NULL; migración `2026_10_02_120000` |\n"
        "| current_address | VARCHAR(255) | NO | — | Dirección actual del promovente (Task 42/SGP-36, "
        "corrección de usuario — documentada, implementación pendiente de validación): OBLIGATORIA en el "
        "wire (422 si se omite), texto libre con el techo de 255 |\n"
        "| residence_province_id | BIGINT UNSIGNED | NO | FK → provinces | Provincia de residencia del "
        "promovente (Task 42): OBLIGATORIA, sonda sobre la superficie ACTIVA del catálogo |\n"
        "| residence_municipality_id | BIGINT UNSIGNED | NO | FK → municipalities | Municipio de residencia "
        "del promovente (Task 42): OBLIGATORIO, coherencia RN-004 sondeada — el municipio pertenece a la "
        "provincia de residencia declarada (422; P-09 para el municipio especial) |\n"
        "| collection_agency_type_id | BIGINT UNSIGNED | NO | FK → agency_types | Tipo de agencia de cobro "
        "del promovente (Task 42): OBLIGATORIO, sonda ACTIVA; su `payment_form` decide la exigencia de la "
        "cuenta bancaria |\n"
        "| collection_agency_id | BIGINT UNSIGNED | NO | FK → agencies | Agencia de cobro del promovente "
        "(Task 42): OBLIGATORIA, sonda ACTIVA y de-ese-tipo — la agencia pertenece al tipo declarado (422) "
        "|\n"
        "| bank_account | VARCHAR(34) | SÍ | — | Cuenta bancaria del cobro (Task 42): OBLIGATORIA "
        "CONDICIONADA — exigida (422 sobre `bank_account`) cuando la forma de pago del tipo de agencia de "
        "cobro es `tarjeta magnetica`, OPCIONAL con `nomina electronica` (omisión = NULL; proveerla se "
        "permite) —; el grupo entero de domicilio y cobro es EDITABLE por PUT (el usuario lo explicitó: "
        "pueden modificarse) |\n",
    ),
    # 5.7 income_concept_records: applied_percent row.
    (
        "| amount | DECIMAL(12,2) | NO | CHECK ≥ 0 | Valor declarado del concepto (RN-005) |\n",
        "| amount | DECIMAL(12,2) | NO | CHECK ≥ 0 | Valor declarado del concepto (RN-005) |\n"
        "| applied_percent | DECIMAL(5,2) | NO | CHECK 0–100, DEFAULT 0.00 | Porciento a aplicar "
        "(Task 42/SGP-36, corrección de usuario — documentada, implementación pendiente de validación): "
        "Double OBLIGATORIO en el wire con rango 0–100 y 2 decimales exactos (cadena decimal por la "
        "doctrina RN-005, jamás float); el DEFAULT solo cubre escrituras fuera del wire |\n",
    ),
    # 5.7 income_concept_records: closing note gains the applied_percent rule.
    (
        "Sin timestamps ni autoría propias (las convenciones del agregado): las bajas son físicas y "
        "auditadas con los valores previos (ADR-19). El par (caso, concepto) se sondea semánticamente "
        "antes del insert (422 sobre `income_concept_id`, RN-008) y el catálogo se exige ACTIVO en alta y "
        "edición.",
        "Sin timestamps ni autoría propias (las convenciones del agregado): las bajas son físicas y "
        "auditadas con los valores previos (ADR-19). El par (caso, concepto) se sondea semánticamente "
        "antes del insert (422 sobre `income_concept_id`, RN-008) y el catálogo se exige ACTIVO en alta y "
        "edición. El porciento a aplicar (Task 42) viaja por fila en el payload anidado y en el endpoint "
        "propio: 422 si se omite, si queda fuera del rango 0–100 o si trae más de dos decimales, con el "
        "CHECK `chk_income_concept_records_applied_percent` como última línea.",
    ),
    # 5.8 pension_payments proposal: the classifier FK leaves.
    (
        "| payment_type_id | BIGINT UNSIGNED | NO | FK → payment_types | Tipo de pago |\n",
        "",
    ),
    # 5.8 pension_payments proposal: UNIQUE without the classifier.
    (
        "| — | — | — | UNIQUE | (`pensioner_id`, `period_year`, `period_month`, `payment_type_id`) |\n",
        "| — | — | — | UNIQUE | (`pensioner_id`, `period_year`, `period_month`) — un pago por pensionado y "
        "período (Task 42: sin clasificador de tipo de pago, eliminado) |\n",
    ),
    # 6. Enums: PaymentForm row.
    (
        "| `PaymentStatus` | `pending`, `paid`, `cancelled` | `pension_payments.status` | pending→paid/"
        "cancelled (propuesta) |\n",
        "| `PaymentStatus` | `pending`, `paid`, `cancelled` | `pension_payments.status` | pending→paid/"
        "cancelled (propuesta) |\n"
        "| `PaymentForm` | `tarjeta magnetica`, `nomina electronica` | `agency_types.payment_form` (CHECK) "
        "| Minúsculas unificadas, sin tildes (Task 42/SGP-36 — documentada, pendiente de validación); sin "
        "transiciones: decide la exigencia de la cuenta bancaria del cobro del expediente |\n",
    ),
    # 8. Indexes: payments-per-period row loses the classifier; new residence row.
    (
        "| Expedientes por proponente | FK `applicant_person_id` (índice automático) |\n"
        "| Serie salarial del expediente | UNIQUE (`pension_case_id`, `year`) |",
        "| Expedientes por proponente | FK `applicant_person_id` (índice automático) |\n"
        "| Expedientes por municipio de residencia | FK `residence_municipality_id` (índice automático; "
        "Task 42, documentada pendiente de validación) |\n"
        "| Serie salarial del expediente | UNIQUE (`pension_case_id`, `year`) |",
    ),
    (
        "| Pagos por período (propuesta) | UNIQUE (`pensioner_id`, `period_year`, `period_month`, "
        "`payment_type_id`) cubre rangos por pensionado; añadir `(period_year, period_month)` si se aprueba "
        "el módulo |",
        "| Pagos por período (propuesta) | UNIQUE (`pensioner_id`, `period_year`, `period_month`) cubre "
        "rangos por pensionado; añadir `(period_year, period_month)` si se aprueba el módulo (Task 42: sin "
        "clasificador de tipo de pago, eliminado) |",
    ),
    # 10. Seeders: CatalogsSeeder row.
    (
        "| `CatalogsSeeder` | Razas, niveles educacionales, categorías ocupacionales y científicas, tipos "
        "de pensión, tipos de beneficiario, tipos de agencia, tipos de entidad/oficina, tipos de pago, "
        "conceptos de ingreso, cargos base | `name` / `code` |",
        "| `CatalogsSeeder` | Razas, niveles educacionales, categorías ocupacionales y científicas, tipos "
        "de pensión, tipos de beneficiario, tipos de agencia (con su `payment_form`, Task 42), tipos de "
        "entidad/oficina, conceptos de ingreso, cargos base — sin tipos de pago, eliminados por la "
        "Task 42 | `name` / `code` |",
    ),
    # 5.7 closing semantics paragraph: the new group.
    (
        "`pension_case_histories` aún no está migrada: llega con las transiciones de S6 (RF-EXP-009, "
        "RF-AUD-002).",
        "`pension_case_histories` aún no está migrada: llega con las transiciones de S6 (RF-EXP-009, "
        "RF-AUD-002). Desde la Task 42 (corrección de usuario, SGP-36 — documentada, implementación "
        "pendiente de validación; migración planificada "
        "`2026_10_03_100200_add_promovente_residence_and_collection_to_pension_cases_table`) el expediente "
        "gana el grupo de DOMICILIO y COBRO del promovente: dirección actual (`current_address`), provincia "
        "y municipio de residencia con coherencia RN-004 sondeada (422; P-09 para el municipio especial), "
        "tipo de agencia de cobro y agencia de cobro con sondas ACTIVA y de-ese-tipo, y cuenta bancaria "
        "OBLIGATORIA CONDICIONADA a la forma de pago `tarjeta magnetica` del tipo de agencia de cobro "
        "(opcional con `nomina electronica`) — el grupo entero es EDITABLE por PUT (el usuario lo "
        "explicitó), a diferencia de la esfera inmutable de la persona —, con las mismas convenciones de "
        "autoría y bitácora del agregado.",
    ),
]

MODELO_CHANGELOG_1_25 = (
    "| 1.25 | 2026-10-02 | Corrección de usuario (Task 40, SGP-34): ciclo de vida del expediente — `PUT "
    "/pension-cases/{id}` con el PROMOVENTE inmutable (los campos de la esfera de la persona y los de "
    "ciclo de vida responden 422 prohibido; semántica PATCH sobre los campos propios) y `DELETE "
    "/pension-cases/{id}` como soft delete SOLO en `submitted` con la reservación de un-abierto-por-persona "
    "liberada (migración `2026_10_02_130000`: `open_case_key` vale NULL también cuando `deleted_at` no es "
    "NULL) — semántica 5.7 y lista de migraciones actualizadas | Arq. Backend |"
)

MODELO_CHANGELOG_1_26 = (
    "| 1.26 | 2026-10-03 | Corrección de usuario (Task 42, SGP-36; DOCUMENTADA, implementación pendiente "
    "de la validación del usuario): eliminación del catálogo `payment_types` (glosario, ER 4.1/4.6, "
    "diccionario 5.2, propuesta `pension_payments` sin clasificador, índices y seeder); `agency_types` gana "
    "`payment_form` (enum en MINÚSCULAS unificadas `tarjeta magnetica`/`nomina electronica`, DEFAULT "
    "`tarjeta magnetica`; sección 5.1, ER 4.2 y enum `PaymentForm` en la sección 6); `pension_cases` gana "
    "el grupo de DOMICILIO y COBRO del promovente (seis columnas en 5.7 — todos obligatorios salvo la "
    "cuenta bancaria, OBLIGATORIA CONDICIONADA a la forma de pago `tarjeta magnetica` del tipo de agencia "
    "de cobro y el grupo editable por PUT —; semántica 5.7 e índice de residencia); "
    "`income_concept_records` gana `applied_percent` DECIMAL(5,2) obligatorio 0–100 con 2 decimales "
    "exactos — SIN cambios de esquema aplicados: esperar la validación del usuario para implementar "
    "| Arq. Backend |"
)

MODELO.append((MODELO_CHANGELOG_1_25, MODELO_CHANGELOG_1_25 + "\n" + MODELO_CHANGELOG_1_26))

ARQUITECTURA: list[tuple[str, str]] = [
    # 3.2 generic-catalog paragraph: counts adjust to the elimination.
    (
        "Los 18 catálogos de la Fase 1 aterrizaron con un patrón de registry",
        "Los 17 catálogos de la Fase 1 aterrizaron con un patrón de registry (18 originales menos "
        "`payment_types`, eliminado por la Task 42 — documentada, pendiente de implementación)",
    ),
    (
        "cubre los 16 catálogos uniformes tras `/api/v1/catalogs/{type}`",
        "cubre los 15 catálogos uniformes tras `/api/v1/catalogs/{type}`",
    ),
    (
        "`true` para los dieciséis, así TODOS los listados devuelven el campo `code`",
        "`true` para los quince, así TODOS los listados devuelven el campo `code`",
    ),
    (
        "Los 18 modelos extienden `CatalogModel`",
        "Los 17 modelos extienden `CatalogModel`",
    ),
    # 9.2 catalogs row: count + the Task 42 clause.
    (
        "CRUD de catálogos uniformes (recurso genérico ADR-15: 16 tipos, todos con `code` desde Task 31)",
        "CRUD de catálogos uniformes (recurso genérico ADR-15: 15 tipos, todos con `code` desde Task 31)",
    ),
    (
        "Task 39/SGP-33: el Schema de Entrada del POST y del PATCH en la spec OpenAPI reconoce ambos campos "
        "(`sector` entero opcional; `deceased_person` booleano con `default` false documentado), anclado "
        "campo a campo por el contrato de ApiDocsTest |",
        "Task 39/SGP-33: el Schema de Entrada del POST y del PATCH en la spec OpenAPI reconoce ambos campos "
        "(`sector` entero opcional; `deceased_person` booleano con `default` false documentado), anclado "
        "campo a campo por el contrato de ApiDocsTest; Task 42/SGP-36 (corrección de usuario, DOCUMENTADA "
        "PENDIENTE DE VALIDACIÓN): el tipo `payment-types` se RETIRA del recurso genérico (el catálogo "
        "`payment_types` se elimina del modelo: la forma de pago del cobro pasa a vivir en el tipo de "
        "agencia) y `agency-types` gana `payment_form` — enum en MINÚSCULAS unificadas `tarjeta "
        "magnetica`/`nomina electronica`, OBLIGATORIO con DEFAULT `tarjeta magnetica` (la omisión del alta "
        "cae en el default, PATCH `sometimes`), devuelto por TODOS sus endpoints —, con el Schema de "
        "Entrada del POST/PATCH y el espejo `CatalogItem` a anclar en ApiDocsTest (spec 1.3.0 → 1.4.0 como "
        "señal de frescura) |",
    ),
    # 9.2 cases POST row: the residence + collection group.
    (
        "fecha de desvinculación del promovente opcional (Task 38/SGP-32: `termination_date` DATE, regla "
        "de forma Y-m-d, omisión = NULL, devuelta en 201/detalle/listado), subregistros declarados en la "
        "MISMA transacción",
        "fecha de desvinculación del promovente opcional (Task 38/SGP-32: `termination_date` DATE, regla "
        "de forma Y-m-d, omisión = NULL, devuelta en 201/detalle/listado), domicilio y cobro del promovente "
        "(Task 42/SGP-36, corrección de usuario, DOCUMENTADA PENDIENTE DE VALIDACIÓN: `current_address` "
        "VARCHAR(255) OBLIGATORIA, `residence_province_id`/`residence_municipality_id` FK OBLIGATORIAS con "
        "coherencia RN-004 sondeada, `collection_agency_type_id`/`collection_agency_id` FK OBLIGATORIAS con "
        "la agencia ACTIVA y de-ese-tipo, y `bank_account` VARCHAR(34) NULL OBLIGATORIA CONDICIONADA — "
        "exigida cuando la forma de pago del tipo de agencia de cobro es `tarjeta magnetica`, opcional con "
        "`nomina electronica` —, devueltos con sus proyecciones en 201/detalle/listado y EDITABLES por "
        "PUT), subregistros declarados en la MISMA transacción",
    ),
    # 9.2 PUT/DELETE row: the editable group.
    (
        "jamás deriva silenciosa del promovente que el registro ya conoce —; DELETE es la eliminación "
        "LÓGICA",
        "jamás deriva silenciosa del promovente que el registro ya conoce —; Task 42/SGP-36 (documentada "
        "pendiente de validación): el grupo de DOMICILIO y COBRO del promovente — dirección actual, "
        "provincia y municipio de residencia, tipo de agencia de cobro, agencia de cobro y cuenta bancaria "
        "— SÍ es editable por PUT (decisión explícita del usuario: pueden modificarse) con probes espejo "
        "del alta y la exigencia condicional de la cuenta re-evaluada contra el estado RESULTANTE (cambiar "
        "el tipo de agencia de cobro a uno con forma de pago `tarjeta magnetica` exige la cuenta si aún es "
        "NULL) —; DELETE es la eliminación LÓGICA",
    ),
    # 9.2 subrecords row: applied_percent.
    (
        "los CONCEPTOS DE INGRESO (regla 5) declaran un valor por par caso-concepto (UNIQUE, 422 "
        "semántico sobre `income_concept_id`) con catálogo activo sondeado y DECIMAL(12,2) no negativo;",
        "los CONCEPTOS DE INGRESO (regla 5) declaran un valor por par caso-concepto (UNIQUE, 422 "
        "semántico sobre `income_concept_id`) con catálogo activo sondeado y DECIMAL(12,2) no negativo, "
        "más el porciento a aplicar `applied_percent` DECIMAL(5,2) OBLIGATORIO con rango 0–100 y 2 "
        "decimales exactos (Task 42/SGP-36, documentada pendiente de validación: 422 si se omite, fuera "
        "del rango o con más de dos decimales; DEFAULT 0.00 solo para escrituras fuera del wire; viaja por "
        "fila en el alta anidada y en el endpoint propio);",
    ),
    # ADR-15 row tail: the shared-contract count.
    (
        "El registry es fuente única para validación, serialización y observadores: agregar un catálogo es "
        "una entrada de datos, no código nuevo; 18 tablas comparten 6 piezas de contratos |",
        "El registry es fuente única para validación, serialización y observadores: agregar un catálogo es "
        "una entrada de datos, no código nuevo; 17 tablas comparten 6 piezas de contratos (18 originales "
        "menos `payment_types`, eliminado por la Task 42 — ver ADR-35) |",
    ),
]

ADR_34_TAIL = (
    "las oficinas NAC/PRO también asientan geografía completa (columnas NOT NULL), así que la vía "
    "«oficina sin municipio» solo emerge por municipio soft-deleted y responde 422 conversacional; los "
    "tests asertan el FORMATO y la INDEPENDENCIA por municipio, nunca valores absolutos |"
)

ADR_35 = (
    "| ADR-35 | Forma de pago en el tipo de agencia, domicilio y cobro del promovente, porciento a aplicar "
    "del concepto y eliminación del catálogo Tipo de pago (corrección de usuario, Task 42/SGP-36; "
    "DOCUMENTADA, implementación pendiente de la validación del usuario): (1) el catálogo `payment_types` "
    "se ELIMINA — migración que cae la tabla, retiro del registry (quedan 15 tipos uniformes), del "
    "seeder, de la enumeración de tipos válidos en la descripción OA y de los tests que lo nombran; la "
    "forma de pago deja de ser un catálogo transversal porque vive EN el tipo de agencia "
    "(`agency_types.payment_form` VARCHAR(20) NOT NULL DEFAULT 'tarjeta magnetica' + CHECK del enum, "
    "valores en MINÚSCULAS unificadas `tarjeta magnetica`/`nomina electronica`, sin tildes, tal como los "
    "escribió el usuario) que viaja por la maquinaria extraRules del recurso genérico (patrón Task 38: "
    "devuelto por TODOS los endpoints, omisión del alta cae en el DEFAULT, PATCH relajado a `sometimes`); "
    "(2) el expediente gana el grupo de DOMICILIO del promovente (`current_address` VARCHAR(255) NOT NULL, "
    "`residence_province_id`/`residence_municipality_id` FK NOT NULL con coherencia RN-004 sondeada) y el "
    "de COBRO (`collection_agency_type_id` FK NOT NULL, `collection_agency_id` FK NOT NULL con sonda "
    "ACTIVA y de-ese-tipo, `bank_account` VARCHAR(34) NULL) — la cuenta bancaria es OBLIGATORIA "
    "CONDICIONADA a la forma de pago del tipo de agencia de cobro resuelto: exigida con `tarjeta "
    "magnetica` (422 sobre `bank_account` si falta o llega vacía), opcional con `nomina electronica` "
    "(omisión = NULL, proveerla se permite) — y TODO el grupo es EDITABLE por PUT (decisión explícita del "
    "usuario: pueden modificarse) a diferencia de la esfera inmutable de la persona, con la exigencia "
    "condicional re-evaluada contra el estado RESULTANTE de la semántica PATCH; (3) el subregistro de "
    "concepto de ingreso gana `applied_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00 con CHECK 0–100 — "
    "Double obligatorio en el wire con rango 0–100 y 2 decimales exactos (cadena decimal por la doctrina "
    "RN-005, jamás float), default solo para escrituras fuera del wire | Mantener `payment_types` como "
    "catálogo (la corrección lo elimina: dos lugares para la misma semántica del cobro), exigir la cuenta "
    "bancaria incondicionalmente (la regla del usuario la ata a la forma de pago del tipo de agencia), "
    "prohibir la cuenta con `nomina electronica` (la corrección la declara opcional, no prohibida), "
    "FLOAT/DOUBLE binario para el porciento (DECIMAL exacto con 2 decimales: la misma doctrina del "
    "dinero) o meter el grupo de cobro en la esfera inmutable del promovente (el usuario explicitó que "
    "PUEDEN modificarse) | La propuesta `pension_payments` pierde el clasificador `payment_type_id` "
    "(UNIQUE de período ajustado a pensionado-año-mes; la forma de pago del cobro ya vive en el "
    "expediente vía el tipo de agencia); la residencia en el municipio especial queda como P-09; los "
    "seeders de tipos de agencia siembran `payment_form` explícito; spec OpenAPI 1.3.0 → 1.4.0 con los "
    "tres frentes anclados por ApiDocsTest (input del catálogo con `payment_form`, expediente con el "
    "grupo nuevo en POST/PUT, subregistro con `applied_percent`) |"
)

ARQUITECTURA.append((ADR_34_TAIL, ADR_34_TAIL + "\n" + ADR_35))

ARQ_CHANGELOG_1_33 = (
    "| 1.33 | 2026-10-03 | Corrección de usuario (Task 41, SGP-35): el listado de expedientes queda con "
    "ALCANCE TERRITORIAL — solo cargan los expedientes cuya oficina coincide con la del usuario "
    "autenticado; `office_id` sale de la query (422 prohibido si llega) y el scope se deriva del puerto "
    "Shared `CurrentUserOfficeProviderInterface` en el controlador con un guard fail-closed en el "
    "servicio (actor sin oficina o criterio ausente → página VACÍA, nunca el directorio sin alcance); "
    "fila de endpoints del GET ampliada, spec OpenAPI 1.3.0 con el parámetro retirado y anclada por "
    "ApiDocsTest — suite 1113/3829 contra MySQL real, Pint/PHPStan 8/deptrac en verde y fumiga de "
    "expedientes ampliada a 61 comprobaciones TODO OK | Arq. Backend |"
)

ARQ_CHANGELOG_1_34 = (
    "| 1.34 | 2026-10-03 | Corrección de usuario (Task 42, SGP-36; DOCUMENTADA, implementación pendiente "
    "de la validación del usuario): cuatro ajustes de modelo — eliminación del catálogo `payment_types` "
    "(la forma de pago vive en `agency_types.payment_form`, enum en minúsculas unificadas `tarjeta "
    "magnetica`/`nomina electronica` con DEFAULT `tarjeta magnetica`); el expediente gana el grupo de "
    "DOMICILIO y COBRO del promovente (`current_address`, provincia y municipio de residencia con RN-004, "
    "tipo de agencia y agencia de cobro, `bank_account` OBLIGATORIA CONDICIONADA a `tarjeta magnetica`; "
    "grupo EDITABLE por PUT); el subregistro de concepto de ingreso gana `applied_percent` "
    "DECIMAL(5,2) obligatorio 0–100 con 2 decimales exactos; y la propuesta `pension_payments` pierde "
    "`payment_type_id` — ADR-35 añadido, filas de endpoints de catálogos/expedientes/subregistros "
    "ampliadas, contajes de catálogos ajustados (15 uniformes / 17 tablas) y changelogs del Modelo de "
    "datos (1.26) y del Plan (1.25) alineados; SIN cambios de código: esperar la validación del usuario "
    "para implementar | Arq. Backend |"
)

ARQUITECTURA.append((ARQ_CHANGELOG_1_33, ARQ_CHANGELOG_1_33 + "\n" + ARQ_CHANGELOG_1_34))

PLAN_NEW_ITEMS = (
    "- [x] Feature tests transaccionales: creación de expediente con subregistros atómica (todo o nada). "
    "✅ 2026-09-28 (puerto `TransactionManager` de Shared materializado con "
    "`DatabaseTransactionManager`; el caso y sus subregistros declarados insertan en una sola transacción)\n"
    "- [ ] **Corrección de usuario (Task 42, SGP-36) — DOCUMENTACIÓN AJUSTADA Y PREPARADA, IMPLEMENTACIÓN "
    "PENDIENTE DE LA VALIDACIÓN DEL USUARIO** (instrucción explícita: «ajustar la documentación de "
    "desarrollo con los siguientes ajustes y preparar para implementarlos, esperar validación para "
    "implementar»; nada de código se ha tocado): cuatro ajustes de modelo refinados por las respuestas "
    "del usuario — enum en MINÚSCULAS unificadas (`tarjeta magnetica` / `nomina electronica`), todos los "
    "campos nuevos obligatorios SALVO la cuenta bancaria (exigida cuando la forma de pago del tipo de "
    "agencia de cobro es `tarjeta magnetica`), el campo del catálogo obligatorio con DEFAULT `tarjeta "
    "magnetica`, el porciento obligatorio con rango 0–100 y 2 decimales, y el grupo nuevo del promovente "
    "MODIFICABLE por PUT.\n"
    "- [ ] (a) ELIMINAR el modelo Tipo de pago (migración planificada `2026_10_03_100000`): caída de la "
    "tabla `payment_types`, retiro del `CatalogRegistry` (quedan 15 tipos uniformes), del `CatalogsSeeder`, "
    "de la enumeración de tipos válidos en la descripción OA del `CatalogController` y de los tres tests "
    "que lo nombran (`CatalogRegistryTest`, `CatalogCrudTest`, `CatalogSeedingTest`); `payment-types` pasa "
    "a responder 404 de catálogo desconocido (`UnknownCatalogException`); sin dependientes — la propuesta "
    "`pension_payments` no está construida —, baja limpia.\n"
    "- [ ] (b) `agency_types.payment_form` VARCHAR(20) NOT NULL DEFAULT 'tarjeta magnetica' + CHECK del "
    "enum (migración planificada `2026_10_03_100100`): enum PHP `PaymentForm` en el dominio de Catalogs "
    "con los valores en minúsculas unificadas tal como los fijó el usuario; wire por la maquinaria "
    "`extraRules` (patrón Task 38): `in:tarjeta magnetica,nomina electronica` con la omisión del alta "
    "cayendo en el DEFAULT y el PATCH relajado a `sometimes`; devuelto por TODOS los endpoints de "
    "`agency-types` (201/detalle/listado/PATCH) con espejo `CatalogItem`; seeder de tipos de agencia con "
    "`payment_form` explícito.\n"
    "- [ ] (c) Grupo de DOMICILIO y COBRO del promovente en `pension_cases` (migración planificada "
    "`2026_10_03_100200`): `current_address` VARCHAR(255) NOT NULL, `residence_province_id`/"
    "`residence_municipality_id` FK NOT NULL con coherencia RN-004 sondeada (422; P-09 para el municipio "
    "especial), `collection_agency_type_id` FK NOT NULL, `collection_agency_id` FK NOT NULL (sondas ACTIVA "
    "y de-ese-tipo) y `bank_account` VARCHAR(34) NULL OBLIGATORIA CONDICIONADA a la forma de pago `tarjeta "
    "magnetica` del tipo de agencia de cobro resuelto (422 sobre `bank_account` si falta; opcional con "
    "`nomina electronica`); recibidos en el POST (todos obligatorios salvo la cuenta), devueltos en "
    "201/detalle/listado con proyecciones (provincia, municipio, tipo de agencia con `payment_form`, "
    "agencia) y EDITABLES por PUT con probes espejo del alta y la exigencia condicional re-evaluada contra "
    "el estado RESULTANTE; `StorePensionCaseRequest`/`UpdatePensionCaseRequest`/`PensionCaseService`/"
    "`PensionCaseResource` + OA del POST/PUT + anclas nuevas de `ApiDocsTest`; fumiga "
    "`smoke_case_registration.php` ampliada (cuenta exigida con `tarjeta magnetica`, opcional con `nomina "
    "electronica`, coherencias de municipio-provincia y agencia-tipo, PUT del grupo).\n"
    "- [ ] (d) `income_concept_records.applied_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00 + CHECK 0–100 "
    "(migración planificada `2026_10_03_100300`): porciento a aplicar Double OBLIGATORIO en el wire con "
    "rango 0–100 y 2 decimales exactos (cadena decimal por la doctrina RN-005, jamás float); viaja por "
    "fila en el alta anidada y en el endpoint propio (`StoreIncomeConceptRecordRequest` + OA + anclas de "
    "ApiDocsTest); DEFAULT 0.00 solo para escrituras fuera del wire.\n"
    "- [ ] Plan de entrega una vez validado: TDD rojo primero en cada frente (ApiDocsTest + suites de "
    "feature: alta sin el grupo → 422, cuenta condicional, coherencias municipio-provincia y "
    "agencia-tipo, porciento fuera de rango o con más decimales, PATCH del catálogo con `payment_form`, "
    "`payment-types` → 404), migraciones en el orden (a)-(d), spec OpenAPI 1.3.0 → 1.4.0 como señal de "
    "frescura, QA completo (Pest + Pint + PHPStan 8 + deptrac 0) y regresión sobre main; la propuesta "
    "`pension_payments` pierde el clasificador `payment_type_id` (UNIQUE de período ajustado a "
    "pensionado-año-mes).\n"
    "\n"
    "**Sprint 6 — Máquina de estados, historial y búsqueda (S6.1-S6.5)**"
)

PLAN: list[tuple[str, str]] = [
    # Sprint 5 tail: the prepared implementation items, before Sprint 6.
    (
        "- [x] Feature tests transaccionales: creación de expediente con subregistros atómica (todo o "
        "nada). ✅ 2026-09-28 (puerto `TransactionManager` de Shared materializado con "
        "`DatabaseTransactionManager`; el caso y sus subregistros declarados insertan en una sola "
        "transacción)\n"
        "\n"
        "**Sprint 6 — Máquina de estados, historial y búsqueda (S6.1-S6.5)**",
        PLAN_NEW_ITEMS,
    ),
]

PLAN_CHANGELOG_1_24 = (
    "| 1.24 | 2026-10-03 | Corrección de usuario (Task 41, SGP-35; ítem S5.2 ampliado): el listado de "
    "expedientes queda con ALCANCE TERRITORIAL — solo cargan los expedientes cuya oficina coincide con la "
    "del usuario autenticado; la oficina NO viaja en la petición (`office_id` prohibido en la query, 422) "
    "y el scope se deriva del puerto Shared `CurrentUserOfficeProviderInterface` con guard fail-closed en "
    "el servicio (sin criterio de oficina → página VACÍA, jamás el directorio sin alcance); spec OpenAPI "
    "1.3.0 anclada por ApiDocsTest — suite 1113/3829 contra MySQL real, Pint/PHPStan 8/deptrac en verde y "
    "fumiga de expedientes ampliada a 61 comprobaciones TODO OK | Arq. Backend |"
)

PLAN_CHANGELOG_1_25 = (
    "| 1.25 | 2026-10-03 | Corrección de usuario (Task 42, SGP-36; DOCUMENTACIÓN AJUSTADA, implementación "
    "pendiente de la validación del usuario): la documentación de desarrollo incorpora los cuatro ajustes "
    "— eliminación del catálogo `payment_types`, `agency_types.payment_form` (enum en minúsculas "
    "unificadas `tarjeta magnetica`/`nomina electronica`, OBLIGATORIO con DEFAULT `tarjeta magnetica`), "
    "grupo de DOMICILIO y COBRO del promovente en el expediente (`current_address`, provincia y municipio "
    "de residencia con RN-004, tipo de agencia y agencia de cobro, `bank_account` OBLIGATORIA CONDICIONADA "
    "a `tarjeta magnetica`, todo el grupo editable por PUT) y `income_concept_records.applied_percent` "
    "(DECIMAL(5,2) obligatorio 0–100 con 2 decimales exactos) — con ADR-35, los ítems de implementación "
    "preparados al cierre del Sprint 5 y los changelogs de Modelo de datos (1.26) y Arquitectura (1.34) "
    "alineados; SIN cambios de código: esperar la validación del usuario para implementar | Arq. Backend |"
)

PLAN.append((PLAN_CHANGELOG_1_24, PLAN_CHANGELOG_1_24 + "\n" + PLAN_CHANGELOG_1_25))

PATCHES: list[tuple[Path, list[tuple[str, str]]]] = [
    (ROOT / "Requisitos funcionales.md", REQUISITOS),
    (ROOT / "Modelo de datos.md", MODELO),
    (ROOT / "Diseño de arquitectura.md", ARQUITECTURA),
    (ROOT / "04_Plan_de_desarrollo.md", PLAN),
]


def main() -> int:
    for path, edits in PATCHES:
        text = path.read_text(encoding="utf-8")
        for old, new in edits:
            count = text.count(old)
            assert count == 1, (
                f"{path.name}: anchor must be unique (or the patch is already "
                f"applied), found {count}: {old[:90]!r}"
            )
            text = text.replace(old, new, 1)
        path.write_text(text, encoding="utf-8")
        print(f"OK  {path.name}: {len(edits)} patches applied")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
