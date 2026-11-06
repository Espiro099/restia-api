"""Pruebas estáticas del contrato. No requieren una API en ejecución.

Ejecutar desde la raíz: pytest tests/contract
RESTIA_SPEC permite probar otra copia del contrato.
"""
import json
import os
import re

import pytest
import yaml
from openapi_spec_validator import validate

SPEC = os.environ.get('RESTIA_SPEC', 'openapi/restia-api.yaml')
d = yaml.safe_load(open(SPEC, encoding='utf-8'))
METHODS = ('get', 'post', 'put', 'patch', 'delete')
OPS = [(m, p, o) for p, v in d['paths'].items() for m, o in v.items() if m in METHODS]
RESP = d['components']['responses']
SCH = d['components']['schemas']
PAR = d['components']['parameters']
ROLES = {'ADMIN', 'MANAGER', 'WAITER', 'KITCHEN'}
KEYS = {'actors', 'branch', 'permission', 'requiredState', 'idempotent', 'requiresVersion', 'concurrency', 'audit', 'outbox', 'mode', 'sensitive', 'clients'}
ALLOWED_STATUS = {'200', '201', '202', '204', '304', '400', '401', '403', '404', '409', '422', '429', '500', '503'}
ids = [o['operationId'] for _, _, o in OPS]


def by_id():
    return pytest.mark.parametrize('m,p,o', OPS, ids=ids)


def ps(o):
    return [q.get('$ref', '').split('/')[-1] for q in o.get('parameters', [])]


def has_cookie(o):
    return {'SessionCookie': []} in o.get('security', [])


def conflict_examples(o):
    r = o['responses']['409']
    if '$ref' in r:
        r = RESP[r['$ref'].split('/')[-1]]
    c = r['content']['application/json']
    if 'examples' in c:
        return {e['value']['error']['code'] for e in c['examples'].values()}
    return {c['example']['error']['code']}


def props(name, seen=None):
    """Nombres de todas las propiedades alcanzables desde un esquema."""
    seen = seen if seen is not None else set()
    found = set()

    def walk(n):
        if isinstance(n, dict):
            if '$ref' in n:
                t = n['$ref'].split('/')[-1]
                if t not in seen:
                    seen.add(t)
                    walk(SCH[t])
            for k, v in n.get('properties', {}).items():
                found.add(k)
                walk(v)
            for k in ('items', 'allOf', 'oneOf', 'anyOf'):
                if k in n:
                    walk(n[k])
        elif isinstance(n, list):
            for i in n:
                walk(i)
    walk(SCH[name])
    return found


def test_openapi_valido():
    validate(d)


def test_operation_ids_unicos():
    assert len(ids) == len(set(ids))


@by_id()
def test_responde_criterios_de_la_seccion_36(m, p, o):
    x = o['x-restia']
    assert KEYS <= set(x)
    assert x['actors'] and x['branch'] and x['permission'] and x['requiredState'] and x['mode'] and x['sensitive'] and x['clients']
    assert any(s[0] == '2' for s in o['responses'])


@by_id()
def test_estados_http_permitidos(m, p, o):
    assert set(o['responses']) <= ALLOWED_STATUS
    assert '500' in o['responses']


@by_id()
def test_parametros_de_ruta_declarados(m, p, o):
    names = set()
    for q in o.get('parameters', []):
        names.add(PAR[q['$ref'].split('/')[-1]]['name'] if '$ref' in q else q['name'])
    assert set(re.findall(r'\{(\w+)\}', p)) <= names


@by_id()
def test_if_match_implica_400_y_409(m, p, o):
    if 'IfMatch' in ps(o):
        assert '400' in o['responses'] and '409' in o['responses']
        assert 'VERSION_CONFLICT' in o['x-restia']['conflictCodes']
    if o['x-restia']['requiresVersion'] is True:
        assert 'IfMatch' in ps(o)


@by_id()
def test_idempotency_key_implica_409(m, p, o):
    if 'IdempotencyKey' in ps(o):
        assert 'IDEMPOTENCY_KEY_REUSED' in o['x-restia']['conflictCodes']


@by_id()
def test_codigos_409_precisos(m, p, o):
    if '409' not in o['responses']:
        assert 'conflictCodes' not in o['x-restia']
        return
    cs = o['x-restia']['conflictCodes']
    assert cs and len(cs) == len(set(cs))
    assert set(cs) <= conflict_examples(o)


def test_todo_codigo_409_tiene_operacion():
    used = {c for _, _, o in OPS for c in o['x-restia'].get('conflictCodes', [])}
    declared = {c for _, _, o in OPS if '409' in o['responses'] for c in conflict_examples(o)}
    assert declared <= used, declared - used


@by_id()
def test_escritura_con_cookie_exige_csrf(m, p, o):
    if m != 'get' and has_cookie(o):
        assert 'XsrfTokenRequired' in ps(o) or 'XsrfTokenWeb' in ps(o)


def test_sin_borrado_fisico():
    assert not [o['operationId'] for m, _, o in OPS if m == 'delete']


def test_todo_privado_exige_autenticacion():
    sin_seguridad = {o['operationId'] for _, _, o in OPS if not o.get('security')}
    extra = {i for i in sin_seguridad if not re.search(r'[Cc]srf|[Ll]ogin|[Rr]efresh|[Ee]nroll|PasswordReset|resetPassword', i)}
    assert not extra, extra


# Mínimo privilegio de las identidades técnicas (sección 23)
AI_OPS = {'getReservationAvailability', 'lookupReservation', 'createReservation', 'confirmReservation', 'cancelReservation', 'getReservationPolicy', 'getCurrentServiceIdentity'}
AGENT_OPS = {'getPrintAgentConfig', 'sendPrintAgentHeartbeat', 'claimPrintJobs', 'startPrintAttempt', 'reportPrintAttemptResult', 'getCurrentServiceIdentity'}


def test_reservation_ai_solo_en_sus_operaciones():
    assert {o['operationId'] for _, _, o in OPS if 'reservation-ai' in o['x-restia']['actors']} == AI_OPS


def test_printing_agent_solo_en_sus_operaciones():
    assert {o['operationId'] for _, _, o in OPS if 'printing-agent' in o['x-restia']['actors']} == AGENT_OPS


def test_service_auth_solo_en_operaciones_de_servicio():
    assert {o['operationId'] for _, _, o in OPS if {'ServiceAuth': []} in o.get('security', [])} == AI_OPS | AGENT_OPS


# Reglas de rol aprobadas
@pytest.mark.parametrize('tag,role', [('Payments', 'KITCHEN'), ('Cash', 'KITCHEN'), ('Fiscal', 'KITCHEN'), ('Customers', 'KITCHEN'), ('Reports', 'WAITER'), ('Reports', 'KITCHEN'),
                                      ('Audit', 'WAITER'), ('Audit', 'KITCHEN'), ('Users', 'WAITER'), ('Users', 'KITCHEN'), ('Inventory', 'WAITER')])
def test_rol_sin_acceso_al_modulo(tag, role):
    assert not [o['operationId'] for _, _, o in OPS if o['tags'][0] == tag and role in o['x-restia']['actors']]


@pytest.mark.parametrize('oid', ['openCashShift', 'createCashMovement', 'startCashShiftClosing', 'closeCashShift', 'createRefund', 'reconcilePayment', 'reconcileRefund',
                                 'cancelInvoice', 'createCreditNote', 'createInvoiceBatch'])
def test_solo_gerentes(oid):
    o = next(o for _, _, o in OPS if o['operationId'] == oid)
    assert set(o['x-restia']['actors']) <= {'ADMIN', 'MANAGER'}


@pytest.mark.parametrize('oid', ['createFiscalIssuer', 'uploadFiscalIssuerCsd', 'createServiceIdentity', 'rotateServiceIdentityToken', 'revokeServiceIdentity', 'anonymizeCustomer', 'listGlobalAuditLog'])
def test_solo_admin(oid):
    o = next(o for _, _, o in OPS if o['operationId'] == oid)
    assert o['x-restia']['actors'] == ['ADMIN']


# Exposición de datos (secciones 11 y 22, F-12, IA-4)
@pytest.mark.parametrize('schema', ['PublicContext', 'PublicMenu'])
def test_publico_sin_datos_internos(schema):
    forbidden = {'branchId', 'tableId', 'sessionId', 'code', 'fiscal', 'routes', 'currentRecipeVersion', 'cost', 'unitCost', 'stock', 'createdBy', 'checkId', 'orders', '_id'}
    assert not props(schema) & forbidden


def test_publico_solo_tres_operaciones():
    qr = {o['operationId'] for _, _, o in OPS if {'PublicQrToken': []} in o.get('security', [])}
    assert qr == {'getPublicContext', 'getPublicMenu', 'registerPublicPresence'}


def test_csd_nunca_se_devuelve():
    assert not props('FiscalIssuer') & {'privateKey', 'password', 'certificate'}


def test_tarjeta_sin_datos_completos():
    text = json.dumps({k: v for k, v in SCH.items() if k.startswith('Payment')})
    assert not re.search(r'"(cvv|cardNumber|pan|cvc)"', text, re.I)


@pytest.mark.parametrize('oid', ['confirmReservation', 'cancelReservation'])
def test_ia_debe_verificar_al_cliente(oid):
    o = next(o for _, _, o in OPS if o['operationId'] == oid)
    schema = o['requestBody']['content']['application/json']['schema']['$ref'].split('/')[-1]
    assert 'verification' in SCH[schema]['properties']


def test_secretos_solo_en_respuestas_de_emision():
    assert 'token' not in props('ServiceIdentity')
    assert 'token' in props('ServiceIdentitySecret')


def test_dinero_con_formato_decimal():
    assert SCH['Money']['properties']['amount']['type'] == 'string'


def test_errores_con_formato_uniforme():
    for name in ('ErrorResponse',):
        assert 'error' in SCH[name]['properties']
    for name, r in RESP.items():
        c = r.get('content', {}).get('application/json')
        if not c:
            continue
        for e in (c.get('examples') or {}).values():
            assert {'code', 'message', 'requestId'} <= set(e['value']['error']), name


# Revisión de seguridad
SIGNED_OK = {'priceDelta'}


def request_schemas():
    names = set()
    for _, _, o in OPS:
        for c in o.get('requestBody', {}).get('content', {}).values():
            if '$ref' in c['schema']:
                names.add(c['schema']['$ref'].split('/')[-1])
    return names


def walk_request(fn):
    seen = set()

    def walk(n, path):
        if '$ref' in n:
            t = n['$ref'].split('/')[-1]
            fn(t, None, path)
            if t not in seen and t != 'Money':
                seen.add(t)
                walk(SCH[t], t)
            return
        fn(None, n, path)
        for v in n.get('allOf', []):
            walk(v, path)
        for k, v in n.get('properties', {}).items():
            walk(v, f'{path}.{k}')
        if 'items' in n:
            walk(n['items'], path + '[]')
    for name in request_schemas():
        seen.add(name)
        walk(SCH[name], name)


def test_peticiones_sin_dinero_negativo():
    bad = []

    def fn(ref, node, path):
        if ref == 'Money' and path.split('.')[-1] not in SIGNED_OK:
            bad.append(path)
    walk_request(fn)
    assert not bad, bad


def test_peticiones_cerradas_y_acotadas():
    bad = []

    def fn(ref, node, path):
        if node is None:
            return
        t = node.get('type')
        if 'properties' in node and node.get('additionalProperties') is not False:
            bad.append(('abierto', path))
        if t == 'array' and 'maxItems' not in node:
            bad.append(('sin maxItems', path))
        if t == 'integer' and 'maximum' not in node and 'enum' not in node:
            bad.append(('sin maximum', path))
        if t == 'string' and not any(k in node for k in ('maxLength', 'pattern', 'enum', 'format')):
            bad.append(('sin maxLength', path))
        if node.get('format') == 'uri' and not str(node.get('pattern', '')).startswith('^https://'):
            bad.append(('uri insegura', path))
    walk_request(fn)
    assert not bad, bad


def test_contrasenas_solo_de_escritura():
    bad = [(n, k) for n, s in SCH.items() for k, v in s.get('properties', {}).items() if isinstance(v, dict) and v.get('format') == 'password' and not v.get('writeOnly')]
    assert not bad, bad


@pytest.mark.parametrize('oid,code', [('createServiceIdentity', '201'), ('rotateServiceIdentityToken', '200'), ('createBranchMember', '201'), ('createPasswordSetupLink', '201'), ('getTableQr', '200'), ('rotateTableQr', '200')])
def test_secretos_sin_cache(oid, code):
    o = next(o for _, _, o in OPS if o['operationId'] == oid)
    assert 'Cache-Control' in o['responses'][code]['headers']


@pytest.mark.parametrize('oid', ['createPasswordSetupLink', 'deactivateUser', 'activateUser'])
def test_sin_toma_de_cuentas_entre_sucursales(oid):
    o = next(o for _, _, o in OPS if o['operationId'] == oid)
    assert o['x-restia']['branch'].startswith('Todas las sucursales')


def test_sin_datos_personales_en_query():
    bad = []
    for _, _, o in OPS:
        for q in o.get('parameters', []):
            q = PAR[q['$ref'].split('/')[-1]] if '$ref' in q else q
            if q['in'] == 'query' and re.search(r'phone|email|rfc|name|token|password', q['name'], re.I):
                bad.append((o['operationId'], q['name']))
    assert not bad, bad


@by_id()
def test_transaccion_puede_agotar_reintentos(m, p, o):
    # Prueba técnica: cualquier transacción puede agotar reintentos.
    if m != 'get' and 'transacción' in str(o['x-restia']['mode']):
        assert '503' in o['responses']
