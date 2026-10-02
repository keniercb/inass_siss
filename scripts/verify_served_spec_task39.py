#!/usr/bin/env python3
"""Fumiga de la spec OpenAPI SERVIDA (Task 39 / SGP-33).

Verifica que el Schema de Entrada del catálogo servido en /api/docs reconoce
los campos incorporados (sector, deceased_person) y que la versión de la
spec sirve la señal de frescura 1.1.0.
"""
import json
import sys

spec = json.load(open("/tmp/api-docs.json"))
checks = []


def check(name, cond):
    checks.append((name, bool(cond)))
    print(f"[{'OK' if cond else 'FALLO'}] {name}")


# 1) Señal de frescura
check("info.version == 1.1.0", spec["info"]["version"] == "1.1.0")

# 2) Schema de Entrada del POST /api/v1/catalogs/{type}
post_schema = (
    spec["paths"]["/api/v1/catalogs/{type}"]["post"]["requestBody"]
    ["content"]["application/json"]["schema"]
)
post_props = post_schema.get("properties", {})
sector = post_props.get("sector", {})
dp = post_props.get("deceased_person", {})
check("POST sector presente", "sector" in post_props)
check("POST sector type=integer", sector.get("type") == "integer")
check("POST sector nullable", sector.get("nullable") is True)
check("POST deceased_person presente", "deceased_person" in post_props)
check("POST deceased_person type=boolean", dp.get("type") == "boolean")
check("POST deceased_person default=false", dp.get("default") is False)

# 3) Schema de Entrada del PATCH /api/v1/catalogs/{type}/{id}
patch_schema = (
    spec["paths"]["/api/v1/catalogs/{type}/{id}"]["patch"]["requestBody"]
    ["content"]["application/json"]["schema"]
)
patch_props = patch_schema.get("properties", {})
check("PATCH sector presente", "sector" in patch_props)
check("PATCH deceased_person presente", "deceased_person" in patch_props)

# 4) Espejo CatalogItem (salida)
item = spec["components"]["schemas"].get("CatalogItem", {})
item_props = item.get("properties", {})
check("CatalogItem sector presente", "sector" in item_props)
check("CatalogItem deceased_person presente", "deceased_person" in item_props)

print()
failed = [n for n, ok in checks if not ok]
print(f"TOTAL: {len(checks) - len(failed)}/{len(checks)} comprobaciones OK")
sys.exit(1 if failed else 0)
