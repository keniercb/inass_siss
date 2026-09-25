#!/bin/bash
# Valida sintaxis de bloques Mermaid del Modelo de datos.md renderizándolos con mmdc
set -u
DOC="/home/z/my-project/download/Modelo de datos.md"
OUT="/home/z/my-project/scripts/tmp_mermaid"
mkdir -p "$OUT"

# Extraer bloques mermaid
awk '/```mermaid/{flag=1; n++; next} /```/{flag=0} flag' "$DOC" > "$OUT/all_blocks.txt"

# Separar en archivos individuales
python3 - <<'PY'
import re, pathlib
doc = pathlib.Path("/home/z/my-project/download/Modelo de datos.md").read_text(encoding="utf-8")
blocks = re.findall(r"```mermaid\n(.*?)```", doc, re.S)
for i, b in enumerate(blocks, 1):
    pathlib.Path(f"/home/z/my-project/scripts/tmp_mermaid/block_{i:02d}.mmd").write_text(b, encoding="utf-8")
print(f"Bloques extraidos: {len(blocks)}")
PY

# Renderizar cada bloque (si falla la sintaxis, mmdc devuelve error)
FAIL=0
for f in /home/z/my-project/scripts/tmp_mermaid/block_*.mmd; do
  if mmdc -i "$f" -o "${f%.mmd}.svg" --quiet >/dev/null 2>&1; then
    echo "OK   $(basename "$f")"
  else
    echo "FAIL $(basename "$f")"
    FAIL=1
  fi
done
exit $FAIL
