import yaml
from openapi_spec_validator import validate

d = yaml.safe_load(open('openapi/restia-api.yaml', encoding='utf-8'))
validate(d)
print('OpenAPI valido')
ks = {'actors', 'branch', 'permission', 'requiredState', 'idempotent', 'requiresVersion', 'concurrency', 'audit',
      'outbox', 'mode', 'sensitive', 'clients'}
n, ids, bad = 0, set(), 0
for p, item in d['paths'].items():
    for m, o in item.items():
        if m not in ('get', 'post', 'patch', 'put', 'delete'):
            continue
        n += 1
        ids.add(o['operationId'])
        x = o['x-restia']
        ps = [q.get('$ref', '') for q in o.get('parameters', [])]
        problems = []
        if ks - set(x):
            problems.append(f'x-restia {ks - set(x)}')
        if '500' not in o['responses']:
            problems.append('sin 500')
        if x['requiresVersion'] is True and not any('IfMatch' in q for q in ps):
            problems.append('sin If-Match')
        if x['requiresVersion'] is True and '400' not in o['responses']:
            problems.append('sin 400')
        if m != 'get' and {'SessionCookie': []} in o.get('security', []) and not any('Xsrf' in q for q in ps):
            problems.append('sin CSRF')
        if 'Idempotency-Key' in str(x['idempotent']) and str(x['idempotent']).startswith('Sí') and not any('Idempotency' in q for q in ps):
            problems.append('sin Idempotency-Key')
        if problems:
            bad += 1
            print('FALTA', m, p, problems)
print('operaciones', n, 'ids unicos', len(ids), 'con problemas', bad)
