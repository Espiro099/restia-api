"""Ajusta la colección generada desde el contrato: variables en vez de valores de ejemplo y sin respuestas guardadas."""
import json

import yaml

SRC = 'postman/Restia.postman_collection.json'
spec = yaml.safe_load(open('openapi/restia-api.yaml', encoding='utf-8'))
c = json.load(open(SRC, encoding='utf-8'))
params = set()
TEST = """if (!pm.request.url.getPath().includes('/sanctum/')) pm.test('Lleva X-Request-ID', () => pm.response.to.have.header('X-Request-ID'));
pm.test('No es un error interno', () => pm.expect(pm.response.code).to.be.below(500));
if (pm.response.code >= 400) {
  pm.test('El error sigue ErrorResponse', () => {
    const e = pm.response.json().error;
    pm.expect(e.code).to.be.a('string');
    pm.expect(e.message).to.be.a('string');
    pm.expect(e.requestId).to.be.a('string');
  });
}"""


def fix(item):
    if 'item' in item:
        for i in item['item']:
            fix(i)
        return
    item.pop('response', None)
    r = item['request']
    pa = '/' + '/'.join('{' + s[1:] + '}' if s.startswith(':') else s for s in r['url']['path'])
    sec = spec['paths'][pa][r['method'].lower()].get('security', [])
    web_only = sec == [{'SessionCookie': []}]
    service_only = sec == [{'ServiceAuth': []}]
    public = sec == [] or sec == [{'PublicQrToken': []}]
    r['auth'] = {'type': 'noauth'} if web_only or public else {'type': 'bearer', 'bearer': [{'key': 'token', 'value': '{{serviceToken}}' if service_only else '{{bearerToken}}', 'type': 'string'}]}
    if sec == [{'PublicQrToken': []}]:
        r.setdefault('header', []).append({'key': 'X-QR-Token', 'value': '{{qrToken}}'})
    if web_only:
        r.setdefault('header', []).append({'key': 'Cookie', 'value': 'laravel_session={{webSession}}'})
    for v in r['url'].get('variable', []):
        params.add(v['key'])
        v['value'] = '{{' + v['key'] + '}}'
    for q in r['url'].get('query', []):
        q['disabled'] = q.get('description', {}).get('content', '').startswith('(Required)') is False if isinstance(q.get('description'), dict) else True
    for h in r.get('header', []):
        k = h['key'].lower()
        if k in ('x-request-id', 'idempotency-key'):
            h['value'] = '{{$guid}}'
        elif k == 'x-xsrf-token':
            h['value'] = '{{xsrfToken}}'
            h['disabled'] = 'no aplica' in str(h.get('description'))
        elif k == 'if-match':
            h['value'] = '"{{version}}"'
        elif k == 'if-none-match':
            h['disabled'] = True
        elif k == 'x-approval-token':
            h['disabled'] = True
        elif k == 'x-conversation-id':
            h['disabled'] = True


for i in c['item']:
    fix(i)
c['info']['name'] = 'Restia API'
c['info']['description'] = 'Generada desde openapi/restia-api.yaml. Los valores de cuerpo son de ejemplo. No contiene secretos: los tokens se definen en el ambiente.'
c['event'] = [{'listen': 'test', 'script': {'type': 'text/javascript', 'exec': TEST.split('\n')}}]
c['variable'] = []
json.dump(c, open(SRC, 'w', encoding='utf-8', newline='\n'), ensure_ascii=False, indent=1)

values = [('baseUrl', 'http://127.0.0.1:4010', 'default'), ('bearerToken', '', 'secret'), ('serviceToken', '', 'secret'), ('webSession', '', 'secret'), ('qrToken', '', 'secret'), ('xsrfToken', '', 'secret'), ('version', '1', 'default')]
env = {'name': 'Restia local', 'values': [{'key': k, 'value': v, 'type': t, 'enabled': True} for k, v, t in values] +
       [{'key': k, 'value': '1' if k == 'recipeVersion' else '00000000-0000-4000-8000-000000000001', 'type': 'default', 'enabled': True} for k in sorted(params)]}
json.dump(env, open('postman/Restia.local.postman_environment.json', 'w', encoding='utf-8', newline='\n'), ensure_ascii=False, indent=1)
print('variables de ruta', len(params))
