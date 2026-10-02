#!/usr/bin/env python3
"""Task 39 (SGP-33) — parches de documentación.

Corrección de usuario: "Ajustar el Schema de Entrada de catálogo para que
reconozca los nuevos campos incorporados". Diagnóstico: las anotaciones
OpenAPI y las reglas del registry YA reconocían sector/deceased_person
(Task 38); lo que no los reconocía era la SPEC SERVIDA en el stack de
staging, porque docker-compose no activa L5_SWAGGER_GENERATE_ALWAYS y el
volumen persistente app-storage cachea el api-docs.json de una imagen
anterior. Fix: regeneración en staging, bump de info.version a 1.1.0 como
señal de frescura, default:false documentado en el Schema de Entrada del
alta y anclaje campo a campo en ApiDocsTest.

Dos archivos, parches con ancla única y aserción de unicidad (patrón de
las Tasks 34-38). Suite 1070/3652; fumiga HTTP de la spec servida OK
(versión 1.1.0, sector/deceased_person visibles en POST y PATCH).
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
# 1) Requisitos funcionales.md — RF-API-004 gana el ítem de la spec servida
# ---------------------------------------------------------------------------
RF_API_004_ANCHOR = (
    "**RF-API-004 (S) — Documentación (DA)**\n"
    "- [ ] Especificación OpenAPI generada y publicada en el entorno de desarrollo.\n"
)

RF_API_004_ITEM = RF_API_004_ANCHOR + (
    "- [x] Spec servida siempre vigente (corrección de usuario, Task 39/SGP-33): la regeneración "
    "`L5_SWAGGER_GENERATE_ALWAYS` está activa en desarrollo, tests y staging (`docker-compose.yml`), "
    "de modo que la spec publicada en el entorno refleja SIEMPRE el código desplegado — sin ella, el "
    "volumen persistente `app-storage` sirve un api-docs.json cacheado de una imagen anterior y el "
    "Schema de Entrada de los catálogos deja de reconocer los campos incorporados (`sector`, "
    "`deceased_person`) —; el contrato de ApiDocsTest ancla ambos campos en los schemas de entrada "
    "del POST y del PATCH, y `info.version` (1.1.0) es la señal de frescura de la spec servida.\n"
)

# ---------------------------------------------------------------------------
# 2) Diseño de arquitectura.md — fila de endpoints, sección 9.3, ADR-13 y changelog
# ---------------------------------------------------------------------------
ARQ_ROW_TAIL = (
    "y el PATCH relaja a `sometimes` las reglas `required` de las columnas propias "
    "(editar el sector ya no exige arrastrar `months_per_year`) |"
)

ARQ_ROW_NEW = (
    "y el PATCH relaja a `sometimes` las reglas `required` de las columnas propias "
    "(editar el sector ya no exige arrastrar `months_per_year`); Task 39/SGP-33: el Schema de "
    "Entrada del POST y del PATCH en la spec OpenAPI reconoce ambos campos (`sector` entero "
    "opcional; `deceased_person` booleano con `default` false documentado), anclado campo a campo "
    "por el contrato de ApiDocsTest |"
)

ARQ_93_ANCHOR = (
    "por lo que \"endpoint sin documentar\" rompe el build igual que una capa violada. "
    "La regeneración del spec se controla con `L5_SWAGGER_GENERATE_ALWAYS` "
    "(true en desarrollo y tests; en producción se desactiva y se genera en el pipeline "
    "de despliegue — endurecimiento de la Fase 6)."
)

ARQ_93_NEW = (
    "por lo que \"endpoint sin documentar\" rompe el build igual que una capa violada; desde la "
    "Task 39 también ancla los campos incorporados en el Schema de Entrada de los catálogos "
    "(`sector`/`deceased_person` en el POST y el PATCH) y la versión del spec, de modo que un "
    "schema de entrada que pierde un campo del wire rompe el build igual que un endpoint sin "
    "documentar. La regeneración del spec se controla con `L5_SWAGGER_GENERATE_ALWAYS` "
    "(true en desarrollo, tests y staging — corrección de usuario Task 39/SGP-33: sin ella el "
    "volumen persistente `app-storage` del stack compose sirve un api-docs.json cacheado de la "
    "imagen anterior y el Schema de Entrada deja de reconocer los campos incorporados, "
    "exactamente lo observado tras la Task 38 —; en producción se desactiva y se genera en el "
    "pipeline de despliegue — endurecimiento de la Fase 6). La `info.version` (1.1.0 desde la "
    "Task 39) es la señal de frescura de la spec servida: quien abra la UI debe ver 1.1.0 antes "
    "de confiar en el schema que está leyendo."
)

ARQ_ADR13_ANCHOR = (
    "| ADR-13 | Documentación OpenAPI generada desde el código (atributos en Presentation "
    "+ l5-swagger) | Spec YAML/JSON mantenido a mano o externo al repo | El spec manual se "
    "desincroniza de las rutas; el generado viaja con cada PR y el test de contrato rompe el "
    "build si falta un endpoint |"
)

ARQ_ADR13_NEW = (
    "| ADR-13 | Documentación OpenAPI generada desde el código (atributos en Presentation "
    "+ l5-swagger; AMENDADO por la corrección de usuario de Task 39/SGP-33: la spec se sirve "
    "SIEMPRE regenerada en desarrollo, tests y staging — `L5_SWAGGER_GENERATE_ALWAYS` en el "
    "compose — porque la caché del volumen persistente servía una spec obsoleta cuyo Schema de "
    "Entrada no reconocía los campos incorporados de la Task 38, y ApiDocsTest pasa a anclar "
    "campo a campo los schemas de entrada además de la presencia de endpoints) | Spec YAML/JSON "
    "mantenido a mano o externo al repo | El spec manual se desincroniza de las rutas; el "
    "generado viaja con cada PR y el test de contrato rompe el build si falta un endpoint o si "
    "un schema de entrada pierde un campo del wire |"
)

ARQ_CHANGELOG_ANCHOR = (
    "— filas de endpoints del alta, catálogos y entidades actualizadas | Arq. Backend |\n"
)

ARQ_CHANGELOG_NEW = ARQ_CHANGELOG_ANCHOR + (
    "| 1.31 | 2026-10-02 | Corrección de usuario (Task 39, SGP-33): el Schema de Entrada de los "
    "catálogos en la spec OpenAPI reconoce los campos incorporados — `sector` entero opcional y "
    "`deceased_person` booleano con `default` false documentados en el POST y el PATCH, anclados "
    "campo a campo por ApiDocsTest — y la spec servida regenera SIEMPRE en staging "
    "(`L5_SWAGGER_GENERATE_ALWAYS` en el compose: el volumen `app-storage` si no serviría la "
    "caché de una imagen anterior); `info.version` sube a 1.1.0 como señal de frescura | Arq. "
    "Backend |\n"
)

patch(
    "Requisitos funcionales.md",
    [(RF_API_004_ANCHOR, RF_API_004_ITEM)],
)

patch(
    "Diseño de arquitectura.md",
    [
        (ARQ_ROW_TAIL, ARQ_ROW_NEW),
        (ARQ_93_ANCHOR, ARQ_93_NEW),
        (ARQ_ADR13_ANCHOR, ARQ_ADR13_NEW),
        (ARQ_CHANGELOG_ANCHOR, ARQ_CHANGELOG_NEW),
    ],
)
