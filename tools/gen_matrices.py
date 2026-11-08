import collections
import glob
import re
import yaml


class NoAlias(yaml.SafeDumper):
    def ignore_aliases(self, data):
        return True


p = 'openapi/restia-api.yaml'
d = yaml.safe_load(open(p, encoding='utf-8'))

RESP = d['components']['responses']
METHODS = ('get', 'post', 'put', 'patch', 'delete')
ops = [(m, pa, o) for pa, v in d['paths'].items() for m, o in v.items() if m in METHODS]
order = [t['name'] for t in d['tags']]


def cell(v):
    if v is False or v is None:
        return 'No'
    if v is True:
        return 'Sí'
    return str(v).replace('|', '/').replace('\n', ' ')


def find_errors(node):
    out = []
    if isinstance(node, dict):
        if 'code' in node and 'message' in node and isinstance(node['code'], str):
            out.append((node['code'], node['message']))
        for v in node.values():
            out += find_errors(v)
    elif isinstance(node, list):
        for v in node:
            out += find_errors(v)
    return out


# ---------- Catálogo de errores ----------
master = {'AUTHENTICATION_REQUIRED', 'AUTHENTICATION_INVALID', 'AUTHENTICATION_EXPIRED', 'FORBIDDEN', 'RESOURCE_NOT_FOUND', 'VALIDATION_FAILED',
          'BRANCH_ACCESS_DENIED', 'VERSION_CONFLICT', 'IDEMPOTENCY_KEY_REUSED', 'TABLE_NOT_AVAILABLE', 'TABLE_ALREADY_ASSIGNED', 'SESSION_NOT_ACTIVE',
          'ORDER_INVALID_STATE', 'ORDER_UNIT_INVALID_STATE', 'CHECK_INVALID_STATE', 'CHECK_HAS_CONFIRMED_PAYMENT', 'PAYMENT_INVALID_STATE',
          'PAYMENT_REQUIRES_RECONCILIATION', 'RESERVATION_NOT_AVAILABLE', 'RESERVATION_INVALID_STATE', 'NO_TABLE_AVAILABLE', 'INVENTORY_INSUFFICIENT',
          'INVENTORY_OVERRIDE_REQUIRED', 'CASH_SHIFT_NOT_OPEN', 'CASH_SHIFT_ALREADY_OPEN', 'PRINT_JOB_UNKNOWN', 'FISCAL_OPERATION_FAILED', 'FISCAL_STATUS_UNKNOWN'}
errs = {}
for m, pa, o in ops:
    for st, r in o['responses'].items():
        name = None
        if '$ref' in r:
            name = r['$ref'].split('/')[-1]
            r = RESP[name]
        for code, msg in find_errors(r):
            if st == '409' and code not in o['x-restia']['conflictCodes']:
                continue
            e = errs.setdefault(code, {'msg': msg, 'status': set(), 'ops': set(), 'resp': set()})
            e['status'].add(st)
            e['ops'].add(o['operationId'])
            if name:
                e['resp'].add(name)


def action(c):
    if c == 'VERSION_CONFLICT':
        return 'Volver a leer el recurso y decidir de nuevo'
    if c == 'VERSION_REQUIRED':
        return 'Corregir el cliente: falta If-Match'
    if c == 'IDEMPOTENCY_KEY_REUSED':
        return 'Corregir el cliente; no reintentar con esa clave'
    if c == 'SERVICE_BUSY':
        return 'Reintentar con la misma Idempotency-Key'
    if c == 'TOO_MANY_ATTEMPTS':
        return 'Esperar lo que indica Retry-After'
    if c in ('INTERNAL_ERROR',):
        return 'Reintentar con la misma Idempotency-Key; reportar con requestId'
    if c == 'VALIDATION_FAILED':
        return 'Corregir los campos indicados en details'
    if c.startswith('AUTHENTICATION') or c in ('SESSION_REVOKED',):
        return 'Renovar la sesión o iniciar sesión'
    if c.endswith('INVALID_STATE') or c.endswith('NOT_OPEN') or c.endswith('NOT_ACTIVE') or c.endswith('INACTIVE'):
        return 'Volver a leer el estado; no reintentar igual'
    if 'UNKNOWN' in c or 'RECONCILIATION' in c:
        return 'Resolver o reconciliar antes de seguir'
    if c == 'FORBIDDEN' or c == 'BRANCH_ACCESS_DENIED':
        return 'No reintentar; falta permiso o acceso'
    if c == 'RESOURCE_NOT_FOUND' or c == 'QR_TOKEN_INVALID':
        return 'No reintentar'
    return 'Mostrar el motivo; corregir la situación antes de reintentar'


out = ['# Catálogo de errores', '', '**Estado:** generado desde `openapi/restia-api.yaml`', '**Fecha:** 2026-11-02', '',
       f'Reúne los {len(errs)} códigos que el contrato puede devolver, según el formato `ErrorResponse` de la sección 29 del documento maestro. Se regenera con `tools/gen_matrices.py`; no se edita a mano.', '',
       '## 1. Códigos', '', '| Código | HTTP | Origen | Operaciones | Mensaje | Qué debe hacer el cliente |', '|---|---|---|---|---|---|']
for c in sorted(errs):
    e = errs[c]
    out.append(f"| `{c}` | {', '.join(sorted(e['status']))} | {'Documento maestro' if c in master else 'Nuevo'} | {len(e['ops'])} | {cell(e['msg'])} | {action(c)} |")
missing = sorted(master - set(errs))
why = {'AUTHENTICATION_EXPIRED': 'D-36: ante cualquier `401`, el cliente intenta renovar la sesión, así que no hace falta distinguir la expiración.',
       'INVENTORY_OVERRIDE_REQUIRED': 'Aprobado en Inventory: `INVENTORY_INSUFFICIENT` cubre el caso, con los faltantes en `details`.',
       'ORDER_INVALID_STATE': 'D-07: `orders.status` es una proyección, sin transiciones propias; el estado lo validan las unidades (`ORDER_UNIT_INVALID_STATE`).'}
out += ['', '## 2. Códigos del catálogo inicial que no se usan', '', '| Código | Motivo |', '|---|---|']
for c in missing:
    out.append(f'| `{c}` | {why.get(c, "Sin motivo registrado")} |')
out += ['', '## 3. Notas', '',
        '- **Códigos por operación:** `x-restia.conflictCodes` indica los `409` exactos de cada operación, y la columna Operaciones los cuenta. El detalle por evento está en [Matriz_Transiciones.md](Matriz_Transiciones.md).',
        '- **Validación:** contraseña débil o actual incorrecta, y rango de auditoría excedido, responden `422 VALIDATION_FAILED` con `details`, conforme a la sección 30.',
        '- **`PRINT_JOB_EXPIRED`:** no es una respuesta de la API, sino el valor de `lastError` en un trabajo vencido.',
        '- **`304`:** el contrato lo usa para `ETag`, aunque no aparece en la sección 30 del documento maestro.']
open('docs/Catalogo_Errores.md', 'w', encoding='utf-8', newline='\n').write('\n'.join(out) + '\n')

# ---------- Matriz de endpoints ----------
ROLES = ['ADMIN', 'MANAGER', 'WAITER', 'KITCHEN']
chk = collections.Counter()
total = len(ops)
for m, pa, o in ops:
    x = o['x-restia']
    r = o['responses']
    chk['q1'] += bool(x['actors'])
    chk['q2'] += bool(x['branch'])
    chk['q3'] += bool(x['permission'])
    chk['q4'] += bool(x['requiredState'])
    chk['q5'] += 'requestBody' in o or m == 'get'
    chk['nobody'] += 'requestBody' not in o and m != 'get'
    chk['q6'] += any(s[0] == '2' for s in r)
    chk['q7'] += any(s[0] in '45' for s in r)
    chk['q8'] += x['idempotent'] not in (None, '')
    chk['q9'] += isinstance(x['requiresVersion'], bool)
    chk['q10'] += bool(x['concurrency'])
    chk['q11'] += 'audit' in x
    chk['q12'] += 'outbox' in x
    chk['q13'] += bool(x['mode'])
    chk['q14'] += bool(x['sensitive'])
    chk['q15'] += bool(x['clients'])
crit = ['¿Quién puede invocarlo?', '¿En qué sucursal?', '¿Qué permiso necesita?', '¿Qué estado requiere?', '¿Qué payload recibe?', '¿Qué devuelve?',
        '¿Qué errores puede producir?', '¿Es idempotente?', '¿Requiere versión?', '¿Qué ocurre con concurrencia?', '¿Genera auditoría?', '¿Genera outbox?',
        '¿Es síncrono o asíncrono?', '¿Qué datos son sensibles?', '¿Qué cliente lo consume?']
out = ['# Matriz endpoint × rol × estado', '', '**Estado:** generado desde `openapi/restia-api.yaml`', '**Fecha:** 2026-11-02', '',
       f'Cubre las {total} operaciones del contrato. Se regenera con `tools/gen_matrices.py`; no se edita a mano.', '',
       '## 1. Criterios de la sección 36', '', '| # | Criterio | Operaciones que lo responden |', '|---|---|---|']
for i, c in enumerate(crit, 1):
    extra = {5: f" (las otras {chk['nobody']} son escrituras sin cuerpo, porque la ruta basta)", 7: ' (`x-restia.conflictCodes` da los 409 exactos)', 9: ' (Orders por lotes lleva la versión en el cuerpo)'}.get(i, '')
    out.append(f"| {i} | {c} | {chk['q'+str(i)]} de {total}{extra} |")
out += ['', '## 2. Operaciones', '', 'Otros actores: identidades de servicio (`reservation-ai`, `printing-agent`) o visitantes públicos.', '']
bytag = collections.defaultdict(list)
for t in ops:
    bytag[t[2]['tags'][0]].append(t)
for tag in order + [t for t in bytag if t not in order]:
    if tag not in bytag:
        continue
    out += [f'### {tag} ({len(bytag[tag])})', '', '| Operación | ADMIN | MANAGER | WAITER | KITCHEN | Otros | Permiso | Estado requerido | If-Match | Idempotencia | Auditoría | Outbox | Modo | Clientes | Códigos 409 |', '|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|']
    for m, pa, o in bytag[tag]:
        x = o['x-restia']
        marks = ['Sí' if r in x['actors'] else '–' for r in ROLES]
        others = ', '.join(a for a in x['actors'] if a not in ROLES) or '–'
        out.append(f"| `{m.upper()} {pa}` | {' | '.join(marks)} | {others} | {cell(x['permission'])} | {cell(x['requiredState'])} | {cell(x['requiresVersion'])} | {cell(x['idempotent'])} | {cell(x['audit'])} | {cell(x['outbox'])} | {cell(x['mode'])} | {', '.join(x['clients'])} | {', '.join(x.get('conflictCodes', [])) or '–'} |")
    out.append('')
# Permisos consolidados
pm = collections.defaultdict(set)
for m, pa, o in ops:
    x = o['x-restia']
    perm = str(x['permission'])
    if perm.startswith('Según action'):
        continue
    # "TEST exige printing.manage" es un requisito adicional, no una concesión a todos los actores de la
    # operación: ese permiso sale de sus propias operaciones.
    for q in re.findall(r'(?<!exige )\b[a-z_]+\.[a-z_]+(?:\.[a-z_]+)?\b', perm):
        pm[q] |= set(x['actors'])
out += ['## 3. Permisos consolidados', '', 'Roles que invocan cada permiso según el contrato. Las condiciones por rol, como "solo `WAITER` y `KITCHEN`" o "con token", están en la decisión de cada módulo.', '',
        '| Permiso | ADMIN | MANAGER | WAITER | KITCHEN | Otros |', '|---|---|---|---|---|---|']
for q in sorted(pm):
    a = pm[q]
    out.append(f"| `{q}` | {' | '.join('Sí' if r in a else '–' for r in ROLES)} | {', '.join(sorted(a - set(ROLES))) or '–'} |")
open('docs/Matriz_Endpoints.md', 'w', encoding='utf-8', newline='\n').write('\n'.join(out) + '\n')

# ---------- Matriz de transiciones ----------
files = [('Tables/Sessions', 'Decisiones_Tables_Sessions.md'), ('Reservations', 'Decisiones_Reservations.md'), ('Orders', 'Decisiones_Orders.md'), ('Checks', 'Decisiones_Checks.md'),
         ('Payments', 'Decisiones_Payments.md'), ('Inventory', 'Decisiones_Inventory.md'), ('Cash', 'Decisiones_Cash.md'), ('Fiscal', 'Decisiones_Fiscal.md'), ('Printing', 'Decisiones_Printing.md')]
out = ['# Matriz de transiciones', '', '**Estado:** consolidada desde las decisiones aprobadas', '**Fecha:** 2026-11-02', '',
       'Reúne las tablas de estados de cada módulo. Ante una diferencia, manda la decisión del módulo.', '']
alltext = ''
for name, f in files:
    lines = open('docs/decisiones/' + f, encoding='utf-8').read().split('\n')
    head = ''
    tables = []
    i = 0
    while i < len(lines):
        l = lines[i]
        if l.startswith('#'):
            head = l.lstrip('# ').strip()
        if l.startswith('|') and 'Evento' in l and 'Estado' in l:
            j = i
            while j < len(lines) and lines[j].startswith('|'):
                j += 1
            tables.append((head, lines[i:j]))
            i = j
            continue
        i += 1
    out += [f'## {name}', '']
    for h, t in tables:
        out += [f'### {h}', ''] + t + ['']
        alltext += '\n'.join(t)
extra = {'Authentication y dispositivos': 'Sesiones (`REVOKED`) y dispositivos (`RETIRED`) están en D-36_Ciclo_de_vida_de_credenciales.md.',
         'Cuenta `REFUNDED`': 'Se alcanza cuando se reembolsa todo lo pagado (P-8); sus unidades pueden moverse a otra cuenta si la factura ya tiene egreso total o está cancelada (F-8).',
         'Catálogos': 'Branch, Table, Product, Station, Modifier, Ingredient, CashRegister, Printer, PrintStation, PrintAgent y FiscalIssuer solo tienen `ACTIVE` e `INACTIVE` (Table además `MAINTENANCE`); cada cambio exige `If-Match` y motivo.',
         'Complemento de pago': 'Ver la sección 2 de Decisiones_Fiscal.md.', 'Lote de factura': '`ACTIVE` pasa a `SUPERSEDED` al excluir cuentas (F-7).',
         'Usuario': '`INVITED` pasa a `ACTIVE` al definir la contraseña; `ACTIVE` e `INVITED` pasan a `INACTIVE`; `INACTIVE` pasa a `ACTIVE`.'}
out += ['## Otros recursos', '']
for k, v in extra.items():
    out.append(f'- **{k}:** {v}')
open('docs/Matriz_Transiciones.md', 'w', encoding='utf-8', newline='\n').write('\n'.join(out) + '\n')

with open('tests/contract/casos_negativos.csv', 'w', encoding='utf-8', newline='\n') as f:
    f.write('operacion,metodo,ruta,estado,codigo\n')
    casos = 0
    for m, pa, o in ops:
        for code in o['x-restia'].get('conflictCodes', []):
            f.write(f"{o['operationId']},{m.upper()},{pa},409,{code}\n")
            casos += 1
print('casos negativos', casos)
sch = d['components']['schemas']
for n, s in sch.items():
    if n.endswith('Status') and 'enum' in s and n not in ('CatalogStatus', 'UserStatus', 'InvoiceBatchStatus', 'BranchStatus', 'TableStatus', 'PaymentComplementStatus', 'ServiceIdentityStatus'):
        faltan = [v for v in s['enum'] if v not in alltext]
        print(n, 'sin transición:' if faltan else 'ok', faltan or '')
print('codigos', len(errs), 'no usados', missing, 'ops', total, {k: v for k, v in chk.items() if v != total})
print('escrituras sin cuerpo', chk['nobody'])
print('sin motivo', [c for c in missing if c not in why])
