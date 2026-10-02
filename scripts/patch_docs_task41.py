#!/usr/bin/env python3
"""Patch the four SGP documents for Task 41 / SGP-35 (user correction):
the case listing is scoped to the AUTHENTICATED USER's office — the
office never travels in the request.

Anchored, asserted, idempotent patches (the Task 40 pattern):
- Requisitos funcionales: RF-EXP-011 gains the territorial-scope item
  and drops the office from the wire-filter enumeration.
- Diseño de arquitectura: endpoints table GET row + changelog 1.33.
- 04_Plan_de_desarrollo: S5.2 item + changelog 1.24.
"""

from pathlib import Path

ROOT = Path("/home/z/my-project/download")

PATCHES: list[tuple[Path, list[tuple[str, str]]]] = [
    (
        ROOT / "Requisitos funcionales.md",
        [
            # RF-EXP-011: the office leaves the wire-filter enumeration…
            (
                "**RF-EXP-011 (M) — Búsqueda y filtros (MO)**\n"
                "- [ ] Listado filtrable por estado, oficina, persona, rango de fechas de solicitud y número.\n"
                "- [ ] Exportación CSV del resultado filtrado (ver RF-REP-004).",
                "**RF-EXP-011 (M) — Búsqueda y filtros (MO)**\n"
                "- [ ] Listado filtrable por estado, persona, rango de fechas de solicitud y número.\n"
                "- [ ] Exportación CSV del resultado filtrado (ver RF-REP-004).\n"
                "- [x] ALCANCE TERRITORIAL del listado (corrección de usuario, Task 41/SGP-35): solo cargan los expedientes cuya oficina coincide con la oficina del USUARIO AUTENTICADO — la oficina NO viaja en la petición (422 prohibido si llega: el filtro dejó el wire) porque el servidor la deriva de la asignación del actor (ADR-33/ADR-29, el mismo patrón del alta que asume la oficina del usuario que registra); un actor sin oficina — estado de cuenta legítimo — recibe una página VACÍA (fail-closed, nunca el directorio sin scope), y el alcance compone con los filtros restantes, que angulan DENTRO de la oficina del actor, jamás a través de ella.",
            ),
        ],
    ),
    (
        ROOT / "Diseño de arquitectura.md",
        [
            # Endpoints table: the GET half of the cases row.
            (
                "| GET/POST | `/api/v1/pension-cases` | `cases.view` / `cases.create` | Listado/filtro (estado, oficina, persona, número, fechas) — cada fila viaja con la proyección COMPLETA del promovente (regla de usuario 3, `PersonResource` reutilizado) — y alta (RF-EXP-001",
                "| GET/POST | `/api/v1/pension-cases` | `cases.view` / `cases.create` | Listado con ALCANCE TERRITORIAL (Task 41/SGP-35, corrección de usuario, RF-EXP-011): solo cargan los expedientes cuya oficina coincide con la OFICINA DEL USUARIO AUTENTICADO — `office_id` prohibido en la query (422 con error conversacional), el controlador deriva el scope del puerto Shared `CurrentUserOfficeProviderInterface` (el mismo seam de la regla 0 del alta, ADR-33/ADR-29) y el servicio responde una página VACÍA fail-closed cuando falta el criterio de oficina (actor sin oficina o caller que olvida el scope: jamás el directorio sin alcance) — con los filtros restantes (estado, persona, número, fechas) angulando DENTRO del scope, cada fila viaja con la proyección COMPLETA del promovente (regla de usuario 3, `PersonResource` reutilizado) — y alta (RF-EXP-001",
            ),
            # Changelog 1.33 (appended right after the 1.32 row).
            (
                "| 1.32 | 2026-10-02 | Corrección de usuario (Task 40, SGP-34): ciclo de vida del expediente — `PUT /pension-cases/{id}` edita los campos propios en `submitted` con semántica PATCH y probes espejo del alta mientras el PROMOVENTE queda INMUTABLE (todo campo de la esfera de la persona y los de ciclo de vida responden 422 prohibido), y `DELETE /pension-cases/{id}` soft-delete SOLO en `submitted` con la reservación de un-abierto-por-persona liberada (migración `2026_10_02_130000`: `open_case_key` NULL también en filas borradas) — fila de endpoints añadida; suite 1108/3788 contra MySQL real, Pint/PHPStan 8/deptrac en verde y fumiga HTTP del ciclo de vida de 28 comprobaciones TODO OK | Arq. Backend |",
                "| 1.32 | 2026-10-02 | Corrección de usuario (Task 40, SGP-34): ciclo de vida del expediente — `PUT /pension-cases/{id}` edita los campos propios en `submitted` con semántica PATCH y probes espejo del alta mientras el PROMOVENTE queda INMUTABLE (todo campo de la esfera de la persona y los de ciclo de vida responden 422 prohibido), y `DELETE /pension-cases/{id}` soft-delete SOLO en `submitted` con la reservación de un-abierto-por-persona liberada (migración `2026_10_02_130000`: `open_case_key` NULL también en filas borradas) — fila de endpoints añadida; suite 1108/3788 contra MySQL real, Pint/PHPStan 8/deptrac en verde y fumiga HTTP del ciclo de vida de 28 comprobaciones TODO OK | Arq. Backend |\n"
                "| 1.33 | 2026-10-03 | Corrección de usuario (Task 41, SGP-35): el listado de expedientes queda con ALCANCE TERRITORIAL — solo cargan los expedientes cuya oficina coincide con la del usuario autenticado; `office_id` sale de la query (422 prohibido si llega) y el scope se deriva del puerto Shared `CurrentUserOfficeProviderInterface` en el controlador con un guard fail-closed en el servicio (actor sin oficina o criterio ausente → página VACÍA, nunca el directorio sin alcance); fila de endpoints del GET ampliada, spec OpenAPI 1.3.0 con el parámetro retirado y anclada por ApiDocsTest — suite 1113/3829 contra MySQL real, Pint/PHPStan 8/deptrac en verde y fumiga de expedientes ampliada a 61 comprobaciones TODO OK | Arq. Backend |",
            ),
        ],
    ),
    (
        ROOT / "04_Plan_de_desarrollo.md",
        [
            # S5.2 item: append the SGP-35 amplification at the end of the
            # creation/listing item (the Task 40 chain ends with the
            # lifecycle sentence).
            (
                "suite 1108/3788, fumiga lifecycle propia (`smoke_case_lifecycle.php`) de 28 comprobaciones TODO OK",
                "suite 1108/3788, fumiga lifecycle propia (`smoke_case_lifecycle.php`) de 28 comprobaciones TODO OK ✅ 2026-10-03 AMPLIADA por la corrección de usuario de Task 41 (SGP-35): el GET del listado con ALCANCE TERRITORIAL — solo cargan los expedientes cuya oficina coincide con la del usuario autenticado, `office_id` prohibido en la query (422 conversacional) porque el controlador lo deriva del puerto Shared `CurrentUserOfficeProviderInterface` (el mismo seam de la regla 0 del alta) y el servicio responde una página VACÍA fail-closed sin criterio de oficina (actor sin oficina o caller que olvida el scope: jamás el directorio sin alcance), con los filtros restantes angulando DENTRO del scope — suite 1113/3829 contra MySQL real, Pint/PHPStan 8/deptrac en verde, fumiga de expedientes ampliada a 61 comprobaciones (query prohibida, reasignación provincial/municipal moviendo el scope de punta a punta, actor sin oficina con página vacía) TODO OK",
            ),
            # Changelog 1.24 (appended right after the 1.23 row).
            (
                "| 1.23 | 2026-10-02 | Corrección de usuario (Task 40, SGP-34; ítem S5.2 ampliado): ciclo de vida del expediente — `PUT /pension-cases/{id}` edita los campos propios en `submitted` con semántica PATCH y probes espejo del alta mientras el PROMOVENTE queda INMUTABLE (todo campo de la esfera de la persona y los de ciclo de vida responden 422 prohibido), y `DELETE /pension-cases/{id}` soft-delete SOLO en `submitted` (la fila sobrevive con `deleted_at`, los subregistros quedan físicos, el detalle responde 404) con la reservación de un-abierto-por-persona LIBERADA (migración `2026_10_02_130000`: `open_case_key` NULL también en filas borradas) — suite 1108/3788 contra MySQL real, Pint/PHPStan 8/deptrac en verde y fumiga HTTP del ciclo de vida de 28 comprobaciones TODO OK | Arq. Backend |",
                "| 1.23 | 2026-10-02 | Corrección de usuario (Task 40, SGP-34; ítem S5.2 ampliado): ciclo de vida del expediente — `PUT /pension-cases/{id}` edita los campos propios en `submitted` con semántica PATCH y probes espejo del alta mientras el PROMOVENTE queda INMUTABLE (todo campo de la esfera de la persona y los de ciclo de vida responden 422 prohibido), y `DELETE /pension-cases/{id}` soft-delete SOLO en `submitted` (la fila sobrevive con `deleted_at`, los subregistros quedan físicos, el detalle responde 404) con la reservación de un-abierto-por-persona LIBERADA (migración `2026_10_02_130000`: `open_case_key` NULL también en filas borradas) — suite 1108/3788 contra MySQL real, Pint/PHPStan 8/deptrac en verde y fumiga HTTP del ciclo de vida de 28 comprobaciones TODO OK | Arq. Backend |\n"
                "| 1.24 | 2026-10-03 | Corrección de usuario (Task 41, SGP-35; ítem S5.2 ampliado): el listado de expedientes queda con ALCANCE TERRITORIAL — solo cargan los expedientes cuya oficina coincide con la del usuario autenticado; la oficina NO viaja en la petición (`office_id` prohibido en la query, 422) y el scope se deriva del puerto Shared `CurrentUserOfficeProviderInterface` con guard fail-closed en el servicio (sin criterio de oficina → página VACÍA, jamás el directorio sin alcance); spec OpenAPI 1.3.0 anclada por ApiDocsTest — suite 1113/3829 contra MySQL real, Pint/PHPStan 8/deptrac en verde y fumiga de expedientes ampliada a 61 comprobaciones TODO OK | Arq. Backend |",
            ),
        ],
    ),
]


def main() -> int:
    for path, edits in PATCHES:
        text = path.read_text(encoding="utf-8")
        for old, new in edits:
            count = text.count(old)
            assert count == 1, (
                f"{path.name}: anchor must be unique, found {count}: {old[:90]!r}"
            )
            assert new not in text or new == old, (
                f"{path.name}: patch already applied? {new[:90]!r}"
            )
            text = text.replace(old, new)
        path.write_text(text, encoding="utf-8")
        print(f"OK  {path.name}: {len(edits)} patch(es)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
