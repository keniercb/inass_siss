#!/usr/bin/env python3
"""Task 42 / SGP-36 implementation-closure patch: the four documents
drop the "documented / pending validation" markers — the user
validated and the implementation landed (migrations 2026_10_03_100000
..100300, spec 1.4.0, suite 1133/3945, smokes TODO OK).
"""

from pathlib import Path

ROOT = Path("/home/z/my-project/download")

REQUISITOS: list[tuple[str, str]] = [
    # RF-CAT-001: the elimination item.
    (
        "- [ ] ELIMINACIÓN del catálogo Tipo de pago (corrección de usuario, "
        "Task 42/SGP-36 — documentada, implementación pendiente de la "
        "validación del usuario): la tabla `payment_types`, el modelo "
        "`PaymentType`, su seeder y la superficie `payment-types` del recurso "
        "genérico dejan de existir",
        "- [x] ELIMINACIÓN del catálogo Tipo de pago (corrección de usuario, "
        "Task 42/SGP-36, IMPLEMENTADA — migración `2026_10_03_100000`): la "
        "tabla `payment_types`, el modelo `PaymentType`, su seeder y la "
        "superficie `payment-types` del recurso genérico dejaron de existir",
    ),
    # RF-CAT-001: the payment form item.
    (
        "- [ ] Forma de pago del tipo de agencia (corrección de usuario, "
        "Task 42/SGP-36 — documentada, implementación pendiente de la "
        "validación del usuario): `agency_types.payment_form`",
        "- [x] Forma de pago del tipo de agencia (corrección de usuario, "
        "Task 42/SGP-36, IMPLEMENTADA — migración `2026_10_03_100100`): "
        "`agency_types.payment_form`",
    ),
    # RF-EXP-001: the residence + collection item.
    (
        "- [ ] Domicilio y cobro del promovente (corrección de usuario, "
        "Task 42/SGP-36 — documentada, implementación pendiente de la "
        "validación del usuario): el expediente gana DOS grupos",
        "- [x] Domicilio y cobro del promovente (corrección de usuario, "
        "Task 42/SGP-36, IMPLEMENTADA — migración `2026_10_03_100200`): el "
        "expediente gana DOS grupos",
    ),
    # RF-EXP-002b: the applied percent item.
    (
        "- [ ] Porciento a aplicar (corrección de usuario, Task 42/SGP-36 — "
        "documentada, implementación pendiente de la validación del "
        "usuario): cada declaración lleva `applied_percent` DECIMAL(5,2)",
        "- [x] Porciento a aplicar (corrección de usuario, Task 42/SGP-36, "
        "IMPLEMENTADA — migración `2026_10_03_100300`): cada declaración "
        "lleva `applied_percent` DECIMAL(5,2)",
    ),
    # H-16.
    (
        "(el catálogo `Tipo de pago` fue ELIMINADO por la corrección de "
        "usuario de la Task 42/SGP-36 — documentada, pendiente de "
        "implementación: la forma de pago vive en el tipo de agencia)",
        "(el catálogo `Tipo de pago` fue ELIMINADO por la corrección de "
        "usuario de la Task 42/SGP-36: la forma de pago vive en el tipo de "
        "agencia)",
    ),
    # RF-PAG-003.
    (
        "Corrección de usuario (Task 42/SGP-36 — documentada, implementación "
        "pendiente de validación): el catálogo `Tipo de pago` se ELIMINA del "
        "modelo",
        "Corrección de usuario (Task 42/SGP-36, implementada): el catálogo "
        "`Tipo de pago` se ELIMINÓ del modelo",
    ),
    # P-09: shipped with full RN-04.
    (
        "Documentado con la misma regla de entidades/oficinas/agencias "
        "(RN-004 plena: el municipio especial queda fuera del dominio de "
        "residencia); decisión a validar junto con la implementación "
        "(alternativa: provincia de residencia nullable solo para el "
        "municipio especial)",
        "Implementado con la misma regla de entidades/oficinas/agencias "
        "(RN-004 plena: el municipio especial queda fuera del dominio de "
        "residencia — 422 sobre residence_municipality_id); la alternativa "
        "(provincia de residencia nullable solo para el municipio especial) "
        "queda registrada por si el área funcional la reclama",
    ),
]

MODELO: list[tuple[str, str]] = [
    # 5.1 payment_form row.
    (
        "Forma de pago del cobro (Task 42/SGP-36, corrección de usuario — "
        "documentada, implementación pendiente de validación): enum `tarjeta "
        "magnetica` / `nomina electronica`",
        "Forma de pago del cobro (Task 42/SGP-36, corrección de usuario, "
        "IMPLEMENTADA — migración `2026_10_03_100100`): enum `tarjeta "
        "magnetica` / `nomina electronica`",
    ),
    # 5.2 closing paragraph.
    (
        "Desde la Task 42 (corrección de usuario, SGP-36 — documentada, "
        "implementación pendiente de validación) el catálogo `payment_types` "
        "queda ELIMINADO",
        "Desde la Task 42 (corrección de usuario, SGP-36, IMPLEMENTADA) el "
        "catálogo `payment_types` quedó ELIMINADO",
    ),
    # 5.7 current_address row.
    (
        "Dirección actual del promovente (Task 42/SGP-36, corrección de "
        "usuario — documentada, implementación pendiente de validación): "
        "OBLIGATORIA en el wire",
        "Dirección actual del promovente (Task 42/SGP-36, corrección de "
        "usuario, IMPLEMENTADA): OBLIGATORIA en el wire",
    ),
    # 5.7 applied_percent row.
    (
        "Porciento a aplicar (Task 42/SGP-36, corrección de usuario — "
        "documentada, implementación pendiente de validación): Double "
        "OBLIGATORIO",
        "Porciento a aplicar (Task 42/SGP-36, corrección de usuario, "
        "IMPLEMENTADA): Double OBLIGATORIO",
    ),
    # 5.7 semantics paragraph.
    (
        "Desde la Task 42 (corrección de usuario, SGP-36 — documentada, "
        "implementación pendiente de validación; migración planificada "
        "`2026_10_03_100200_add_promovente_residence_and_collection_to_"
        "pension_cases_table`) el expediente gana el grupo de DOMICILIO y "
        "COBRO del promovente",
        "Desde la Task 42 (corrección de usuario, SGP-36, IMPLEMENTADA; "
        "migración `2026_10_03_100200_add_promovente_residence_and_"
        "collection_to_pension_cases_table`) el expediente gana el grupo de "
        "DOMICILIO y COBRO del promovente",
    ),
    # 5.7 migration list gains the Task 42 migrations.
    (
        "`2026_10_02_130000_release_open_case_reservation_on_pension_cases_"
        "soft_delete` (Task 40/SGP-34: la columna generada `open_case_key` "
        "se re-crea con la expresión ampliada que también anula la "
        "reservación en filas soft-deleted — MySQL exige soltar índice y "
        "columna antes de re-añadir ambos con la nueva expresión)",
        "`2026_10_02_130000_release_open_case_reservation_on_pension_cases_"
        "soft_delete` (Task 40/SGP-34: la columna generada `open_case_key` "
        "se re-crea con la expresión ampliada que también anula la "
        "reservación en filas soft-deleted — MySQL exige soltar índice y "
        "columna antes de re-añadir ambos con la nueva expresión), "
        "`2026_10_03_100000_drop_payment_types_table`, "
        "`2026_10_03_100100_add_payment_form_to_agency_types_table`, "
        "`2026_10_03_100200_add_promovente_residence_and_collection_to_"
        "pension_cases_table` y `2026_10_03_100300_add_applied_percent_to_"
        "income_concept_records_table` (Task 42/SGP-36: eliminación del "
        "catálogo de tipos de pago, forma de pago del tipo de agencia, "
        "grupo de domicilio y cobro del promovente con cuenta condicional, "
        "y porciento a aplicar del concepto de ingreso)",
    ),
    # §6 enum row.
    (
        "Minúsculas unificadas, sin tildes (Task 42/SGP-36 — documentada, "
        "pendiente de validación); sin transiciones",
        "Minúsculas unificadas, sin tildes (Task 42/SGP-36, implementada); "
        "sin transiciones",
    ),
    # §8 index row.
    (
        "FK `residence_municipality_id` (índice automático; Task 42, "
        "documentada pendiente de validación)",
        "FK `residence_municipality_id` (índice automático; Task 42)",
    ),
    # Changelog 1.26 → implemented.
    (
        "| 1.26 | 2026-10-03 | Corrección de usuario (Task 42, SGP-36; "
        "DOCUMENTADA, implementación pendiente de la validación del "
        "usuario): eliminación del catálogo `payment_types`",
        "| 1.26 | 2026-10-03 | Corrección de usuario (Task 42, SGP-36; "
        "IMPLEMENTADA): eliminación del catálogo `payment_types`",
    ),
    (
        "exactos — SIN cambios de esquema aplicados: esperar la validación "
        "del usuario para implementar | Arq. Backend |",
        "exactos — migraciones `2026_10_03_100000`..`100300`; suite 1133/3945 "
        "contra MySQL real, Pint/PHPStan 8/deptrac en verde y fumigas TODO "
        "OK | Arq. Backend |",
    ),
]

ARQUITECTURA: list[tuple[str, str]] = [
    # 3.2 counts paragraph.
    (
        "(18 originales menos `payment_types`, eliminado por la Task 42 — "
        "documentada, pendiente de implementación)",
        "(18 originales menos `payment_types`, eliminado por la Task 42)",
    ),
    # 9.2 catalogs row.
    (
        "Task 42/SGP-36 (corrección de usuario, DOCUMENTADA PENDIENTE DE "
        "VALIDACIÓN): el tipo `payment-types` se RETIRA del recurso genérico "
        "(el catálogo `payment_types` se elimina del modelo: la forma de "
        "pago del cobro pasa a vivir en el tipo de agencia) y "
        "`agency-types` gana `payment_form` — enum en MINÚSCULAS unificadas "
        "`tarjeta magnetica`/`nomina electronica`, OBLIGATORIO con DEFAULT "
        "`tarjeta magnetica` (la omisión del alta cae en el default, PATCH "
        "`sometimes`), devuelto por TODOS sus endpoints —, con el Schema de "
        "Entrada del POST/PATCH y el espejo `CatalogItem` a anclar en "
        "ApiDocsTest (spec 1.3.0 → 1.4.0 como señal de frescura) |",
        "Task 42/SGP-36 (corrección de usuario, IMPLEMENTADA — migraciones "
        "`2026_10_03_100000`/`100100`): el tipo `payment-types` se RETIRÓ "
        "del recurso genérico (el catálogo `payment_types` se eliminó del "
        "modelo: la forma de pago del cobro vive en el tipo de agencia; "
        "payment-types responde 404 de catálogo desconocido) y "
        "`agency-types` gana `payment_form` — enum en MINÚSCULAS unificadas "
        "`tarjeta magnetica`/`nomina electronica`, OBLIGATORIO con DEFAULT "
        "`tarjeta magnetica` (la omisión del alta cae en el default, PATCH "
        "`sometimes`), devuelto por TODOS sus endpoints —, con el Schema de "
        "Entrada del POST/PATCH y el espejo `CatalogItem` anclados en "
        "ApiDocsTest (spec 1.4.0 como señal de frescura) |",
    ),
    # 9.2 cases POST row.
    (
        "domicilio y cobro del promovente (Task 42/SGP-36, corrección de "
        "usuario, DOCUMENTADA PENDIENTE DE VALIDACIÓN:",
        "domicilio y cobro del promovente (Task 42/SGP-36, corrección de "
        "usuario, IMPLEMENTADA:",
    ),
    # 9.2 PUT/DELETE row.
    (
        "Task 42/SGP-36 (documentada pendiente de validación): el grupo de "
        "DOMICILIO y COBRO del promovente",
        "Task 42/SGP-36 (implementada): el grupo de DOMICILIO y COBRO del "
        "promovente",
    ),
    # 9.2 subrecords row.
    (
        "más el porciento a aplicar `applied_percent` DECIMAL(5,2) "
        "OBLIGATORIO con rango 0–100 y 2 decimales exactos (Task 42/SGP-36, "
        "documentada pendiente de validación:",
        "más el porciento a aplicar `applied_percent` DECIMAL(5,2) "
        "OBLIGATORIO con rango 0–100 y 2 decimales exactos (Task 42/SGP-36, "
        "IMPLEMENTADA:",
    ),
    # ADR-35 header.
    (
        "(corrección de usuario, Task 42/SGP-36; DOCUMENTADA, implementación "
        "pendiente de la validación del usuario): (1) el catálogo "
        "`payment_types` se ELIMINA",
        "(corrección de usuario, Task 42/SGP-36; IMPLEMENTADA — migraciones "
        "`2026_10_03_100000`..`100300`, spec 1.4.0, suite 1133/3945, fumigas "
        "TODO OK): (1) el catálogo `payment_types` se ELIMINA",
    ),
    # ADR-35 consequences tail.
    (
        "spec OpenAPI 1.3.0 → 1.4.0 con los tres frentes anclados por "
        "ApiDocsTest (input del catálogo con `payment_form`, expediente con "
        "el grupo nuevo en POST/PUT, subregistro con `applied_percent`) |",
        "spec OpenAPI 1.4.0 con los tres frentes anclados por ApiDocsTest "
        "(input del catálogo con `payment_form`, expediente con el grupo "
        "nuevo en POST/PUT, subregistro con `applied_percent`) |",
    ),
    # Changelog 1.34.
    (
        "| 1.34 | 2026-10-03 | Corrección de usuario (Task 42, SGP-36; "
        "DOCUMENTADA, implementación pendiente de la validación del "
        "usuario): cuatro ajustes de modelo",
        "| 1.34 | 2026-10-03 | Corrección de usuario (Task 42, SGP-36; "
        "IMPLEMENTADA): cuatro ajustes de modelo",
    ),
    (
        "alineados; SIN cambios de código: esperar la validación del "
        "usuario para implementar | Arq. Backend |",
        "alineados; IMPLEMENTADA con las migraciones `2026_10_03_100000`.."
        "`100300`, spec OpenAPI 1.4.0 anclada por ApiDocsTest, suite "
        "1133/3945 contra MySQL real, Pint/PHPStan 8/deptrac en verde y "
        "fumigas de expedientes/ciclo de vida ampliadas TODO OK | Arq. "
        "Backend |",
    ),
]

PLAN: list[tuple[str, str]] = [
    # Intro item.
    (
        "- [ ] **Corrección de usuario (Task 42, SGP-36) — DOCUMENTACIÓN "
        "AJUSTADA Y PREPARADA, IMPLEMENTACIÓN PENDIENTE DE LA VALIDACIÓN "
        "DEL USUARIO** (instrucción explícita: «ajustar la documentación de "
        "desarrollo con los siguientes ajustes y preparar para "
        "implementarlos, esperar validación para implementar»; nada de "
        "código se ha tocado): cuatro ajustes de modelo refinados por las "
        "respuestas del usuario",
        "- [x] **Corrección de usuario (Task 42, SGP-36) — IMPLEMENTADA "
        "2026-10-03** (la documentación se ajustó primero y el usuario "
        "validó con «implemnta»; migraciones `2026_10_03_100000`..`100300`, "
        "spec OpenAPI 1.4.0, suite 1133/3945 contra MySQL real, "
        "Pint/PHPStan 8/deptrac en verde, fumigas TODO OK): cuatro ajustes "
        "de modelo refinados por las respuestas del usuario",
    ),
    # Item (a).
    (
        "- [ ] (a) ELIMINAR el modelo Tipo de pago (migración planificada "
        "`2026_10_03_100000`):",
        "- [x] (a) ELIMINAR el modelo Tipo de pago (migración "
        "`2026_10_03_100000`):",
    ),
    # Item (b).
    (
        "- [ ] (b) `agency_types.payment_form` VARCHAR(20) NOT NULL DEFAULT "
        "'tarjeta magnetica' + CHECK del enum (migración planificada "
        "`2026_10_03_100100`):",
        "- [x] (b) `agency_types.payment_form` VARCHAR(20) NOT NULL DEFAULT "
        "'tarjeta magnetica' + CHECK del enum (migración "
        "`2026_10_03_100100`):",
    ),
    # Item (c).
    (
        "- [ ] (c) Grupo de DOMICILIO y COBRO del promovente en "
        "`pension_cases` (migración planificada `2026_10_03_100200`):",
        "- [x] (c) Grupo de DOMICILIO y COBRO del promovente en "
        "`pension_cases` (migración `2026_10_03_100200`):",
    ),
    # Item (d).
    (
        "- [ ] (d) `income_concept_records.applied_percent` DECIMAL(5,2) "
        "NOT NULL DEFAULT 0.00 + CHECK 0–100 (migración planificada "
        "`2026_10_03_100300`):",
        "- [x] (d) `income_concept_records.applied_percent` DECIMAL(5,2) "
        "NOT NULL DEFAULT 0.00 + CHECK 0–100 (migración "
        "`2026_10_03_100300`):",
    ),
    # Delivery plan item.
    (
        "- [ ] Plan de entrega una vez validado: TDD rojo primero en cada "
        "frente",
        "- [x] Plan de entrega (ejecutado): TDD rojo primero en cada frente",
    ),
    # Changelog 1.25.
    (
        "| 1.25 | 2026-10-03 | Corrección de usuario (Task 42, SGP-36; "
        "DOCUMENTACIÓN AJUSTADA, implementación pendiente de la validación "
        "del usuario): la documentación de desarrollo incorpora los cuatro "
        "ajustes",
        "| 1.25 | 2026-10-03 | Corrección de usuario (Task 42, SGP-36; "
        "IMPLEMENTADA tras la validación del usuario): la documentación de "
        "desarrollo incorpora los cuatro ajustes",
    ),
    (
        "— SIN cambios de código: esperar la validación del usuario para "
        "implementar | Arq. Backend |",
        "— implementada con las migraciones `2026_10_03_100000`..`100300`, "
        "spec 1.4.0, suite 1133/3945 y fumigas TODO OK | Arq. Backend |",
    ),
]


def apply(path: Path, edits: list[tuple[str, str]]) -> None:
    text = path.read_text(encoding="utf-8")
    applied = 0
    for old, new in edits:
        count = text.count(old)
        assert count == 1, (
            f"{path.name}: anchor must be unique, found {count}: {old[:90]!r}"
        )
        text = text.replace(old, new, 1)
        applied += 1
    path.write_text(text, encoding="utf-8")
    print(f"OK  {path.name}: {applied} edits applied")


def main() -> int:
    apply(ROOT / "Requisitos funcionales.md", REQUISITOS)
    apply(ROOT / "Modelo de datos.md", MODELO)
    apply(ROOT / "Diseño de arquitectura.md", ARQUITECTURA)
    apply(ROOT / "04_Plan_de_desarrollo.md", PLAN)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
