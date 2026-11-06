"""Genera config/errors.php desde docs/Catalogo_Errores.md. Ejecutar desde la raíz del repositorio.

El catálogo se genera a su vez del contrato (tools/gen_matrices.py), así que el orden es:
gen_matrices.py y luego este script. No editar config/errors.php a mano.
"""
import re

rows = []
for line in open('docs/Catalogo_Errores.md', encoding='utf-8'):
    m = re.match(r'^\| `([A-Z_]+)` \| (\d{3}) \| [^|]+ \| \d+ \| ([^|]+) \|', line)
    if m:
        rows.append((m.group(1), int(m.group(2)), m.group(3).strip()))

if not rows:
    raise SystemExit('No se encontraron códigos en docs/Catalogo_Errores.md')


def php(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"


out = ['<?php', '', '// Generado por tools/gen_error_catalog.py desde docs/Catalogo_Errores.md. No editar a mano.', '', 'return [']
for code, status, message in sorted(rows):
    out.append(f"    '{code}' => ['status' => {status}, 'message' => {php(message)}],")
out.append('];')
open('config/errors.php', 'w', encoding='utf-8', newline='\n').write('\n'.join(out) + '\n')
print('códigos', len(rows))
