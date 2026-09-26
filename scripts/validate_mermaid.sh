#!/bin/bash
# Valida sintaxis de bloques Mermaid de un .md renderizándolos con mmdc
# Uso: ./validate_mermaid.sh [ruta/al/documento.md]
set -u
DOC="${1:-/home/z/my-project/download/Modelo de datos.md}"
OUT="/home/z/my-project/scripts/tmp_mermaid"
mkdir -p "$OUT"
rm -f "$OUT"/block_*.mmd "$OUT"/block_*.svg

python3 - "$DOC" "$OUT" <<'PY'
import re, pathlib, sys
doc = pathlib.Path(sys.argv[1]).read_text(encoding="utf-8")
out = pathlib.Path(sys.argv[2])
blocks = re.findall(r"```mermaid\n(.*?)```", doc, re.S)
for i, b in enumerate(blocks, 1):
    (out / f"block_{i:02d}.mmd").write_text(b, encoding="utf-8")
print(f"Documento: {sys.argv[1]}")
print(f"Bloques extraidos: {len(blocks)}")
PY

FAIL=0
for f in "$OUT"/block_*.mmd; do
  if mmdc -i "$f" -o "${f%.mmd}.svg" --quiet >/dev/null 2>&1; then
    echo "OK   $(basename "$f")"
  else
    echo "FAIL $(basename "$f")"
    FAIL=1
  fi
done
exit $FAIL
