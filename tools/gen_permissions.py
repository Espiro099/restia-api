"""Genera config/permissions.php: la matriz fija de rol a permisos (U-3). Ejecutar desde la raíz.

Fuentes, en este orden:
1. docs/Matriz_Endpoints.md, sección 3 (permisos consolidados del contrato).
2. La tabla de permisos de docs/decisiones/Decisiones_Orders.md, sección 4: agrega los permisos de
   transición de unidades (`order_units.deliver`, `order_units.cancel`, `order_units.prepare`) y da
   `order_units.void` directo a ADMIN y MANAGER. "Con token" no cuenta: ese caso usa X-Approval-Token.
3. Las tablas de permisos de Decisiones_Inventory.md y Decisiones_Printing.md, sección 5: mandan sobre
   lo anterior para sus permisos. Así KITCHEN no recibe `inventory.adjust` por aparecer junto a
   `inventory.record_waste`, y `inventory.override`, que solo usa un aprobador, sí existe.

En 1 y 2 un permiso solo se concede con "Sí". No editar config/permissions.php a mano.
"""
import re

ROLES = ['ADMIN', 'MANAGER', 'WAITER', 'KITCHEN']
grants = {r: set() for r in ROLES}
known = set()


def read(path, start):
    inside = False
    for line in open(path, encoding='utf-8'):
        if line.startswith('## '):
            inside = line.startswith(start)
            continue
        if not inside:
            continue
        m = re.match(r'^\| `([a-z_]+\.[a-z_.]+)`(?: \([^)]*\))? \|(.*)$', line)
        if not m:
            continue
        cells = [c.strip() for c in m.group(2).split('|')][:4]
        known.add(m.group(1))
        for role, cell in zip(ROLES, cells):
            if cell == 'Sí':
                grants[role].add(m.group(1))


read('docs/Matriz_Endpoints.md', '## 3.')
read('docs/decisiones/Decisiones_Orders.md', '## 4.')


def override(path, start):
    """La tabla de permisos de la decisión del módulo manda sobre lo que se infiere del contrato, que
    no distingue condiciones como "inventory.adjust para ADJUSTMENT". Una celda con una restricción
    ("Bloquear, no desbloquear") concede el permiso; la restricción la aplica el código."""
    inside = False
    for line in open(path, encoding='utf-8'):
        if line.startswith('## '):
            inside = line.startswith(start)
            continue
        if not inside:
            continue
        m = re.match(r'^\| `([a-z_]+\.[a-z_.]+)`(?: \([^)]*\))? \|(.*)$', line)
        if not m:
            continue
        cells = [c.strip() for c in m.group(2).split('|')][:4]
        known.add(m.group(1))
        for role, cell in zip(ROLES, cells):
            if cell in ('No', '–', '', 'Con token'):
                grants[role].discard(m.group(1))
            else:
                grants[role].add(m.group(1))


override('docs/decisiones/Decisiones_Inventory.md', '## 5.')
override('docs/decisiones/Decisiones_Printing.md', '## 5.')

if not grants['ADMIN']:
    raise SystemExit('No se encontraron permisos')

out = ['<?php', '', '// Generado por tools/gen_permissions.py. No editar a mano.', '', 'return [']
out.append("    'roles' => [")
for role in ROLES:
    out.append(f"        '{role}' => [")
    out += [f"            '{p}'," for p in sorted(grants[role])]
    out.append('        ],')
out.append('    ],')
out.append("    'known' => [")
out += [f"        '{p}'," for p in sorted(known)]
out.append('    ],')
out.append('];')
open('config/permissions.php', 'w', encoding='utf-8', newline='\n').write('\n'.join(out) + '\n')
print('roles', {r: len(grants[r]) for r in ROLES}, 'permisos', len(known))
