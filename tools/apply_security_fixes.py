"""Aplica al contrato las correcciones de Revision_Seguridad.md."""
import yaml


class NoAlias(yaml.SafeDumper):
    def ignore_aliases(self, data):
        return True


p = 'openapi/restia-api.yaml'
d = yaml.safe_load(open(p, encoding='utf-8'))
S = d['components']['schemas']
OPS = {o['operationId']: o for v in d['paths'].values() for o in v.values() if isinstance(o, dict) and 'operationId' in o}


def R(n): return {'$ref': '#/components/responses/' + n}
def Sc(n): return {'$ref': '#/components/schemas/' + n}


CUR = {'type': 'string', 'pattern': '^[A-Z]{3}$'}
S['Money']['additionalProperties'] = False
S['MoneyPositive'] = {'type': 'object', 'required': ['amount', 'currency'], 'additionalProperties': False, 'properties': {
    'amount': {'type': 'string', 'pattern': '^(?!0+(\\.0+)?$)\\d{1,12}(\\.\\d{1,4})?$', 'description': 'Mayor que 0'}, 'currency': CUR}}
S['MoneyNonNegative'] = {'type': 'object', 'required': ['amount', 'currency'], 'additionalProperties': False, 'properties': {
    'amount': {'type': 'string', 'pattern': '^\\d{1,12}(\\.\\d{1,4})?$', 'description': 'Mayor o igual a 0'}, 'currency': CUR}}


def use(schema, field, target):
    node = S[schema]['properties'][field]
    desc = node.get('description')
    S[schema]['properties'][field] = {'allOf': [Sc(target)], 'description': desc} if desc else Sc(target)


for sc, f, t in [('PaymentCreateRequest', 'amount', 'MoneyPositive'), ('PaymentCreateRequest', 'tip', 'MoneyNonNegative'), ('PaymentCreateRequest', 'amountTendered', 'MoneyPositive'),
                 ('CashMovementCreateRequest', 'amount', 'MoneyPositive'), ('CreditNoteCreateRequest', 'amount', 'MoneyPositive'),
                 ('CashRegisterCreateRequest', 'differenceTolerance', 'MoneyNonNegative'), ('CashRegisterUpdateRequest', 'differenceTolerance', 'MoneyNonNegative'),
                 ('CashShiftOpenRequest', 'openingAmount', 'MoneyNonNegative'), ('CashShiftCloseRequest', 'countedCash', 'MoneyNonNegative'), ('CashShiftCloseRequest', 'cashLeftInDrawer', 'MoneyNonNegative'),
                 ('InventoryReceiptLine', 'unitCost', 'MoneyNonNegative'), ('ProductCreateRequest', 'price', 'MoneyNonNegative'), ('ProductUpdateRequest', 'price', 'MoneyNonNegative')]:
    use(sc, f, t)

# La URL de imagen se muestra en el menú público: sin javascript:, data: ni http.
for sc in ('ProductCreateRequest', 'ProductUpdateRequest'):
    node = S[sc]['properties']['imageUrl']
    node.update({'pattern': '^https://', 'maxLength': 2048})

for n, f in [('FiscalIssuerCsdRequest', 'password'), ('PasswordChangeRequest', 'currentPassword'), ('PasswordChangeRequest', 'newPassword'), ('PasswordResetRequest', 'newPassword')]:
    S[n]['properties'][f]['writeOnly'] = True
S['FiscalIssuerCsdRequest']['properties']['certificate']['maxLength'] = 16384
S['FiscalIssuerCsdRequest']['properties']['privateKey']['maxLength'] = 16384

# Límites contra peticiones desmedidas
for n, f, mx in [('BranchCreateRequest', 'reservationHours', 21), ('BranchUpdateRequest', 'reservationHours', 21), ('ModifierCreateRequest', 'options', 100), ('ModifierUpdateRequest', 'options', 100),
                 ('ModifierOptionInput', 'ingredientAdjustments', 50), ('PrintStationCreateRequest', 'jobTypes', 6), ('PrintStationUpdateRequest', 'jobTypes', 6),
                 ('ProductCreateRequest', 'modifierIds', 50), ('ProductUpdateRequest', 'modifierIds', 50), ('ProductRoutesRequest', 'routes', 20), ('RecipePublishRequest', 'items', 200)]:
    S[n]['properties'][f]['maxItems'] = mx
for n, f, mx in [('CheckMoveUnitsRequest', 'targetCheckVersion', 2147483647), ('ModifierCreateRequest', 'maxSelections', 100), ('ModifierCreateRequest', 'minSelections', 100),
                 ('ModifierUpdateRequest', 'maxSelections', 100), ('ModifierUpdateRequest', 'minSelections', 100), ('ProductCreateRequest', 'displayOrder', 100000), ('ProductUpdateRequest', 'displayOrder', 100000),
                 ('ProductRoute', 'priority', 1000), ('TableCleaningConfirmationRequest', 'membershipVersion', 2147483647), ('TableCreateRequest', 'number', 9999), ('TableUpdateRequest', 'number', 9999)]:
    S[n]['properties'][f]['maximum'] = mx
units = S['OrderUnitTransitionRequest']['properties']['units']['items']
(units if 'properties' in units else S[units['$ref'].split('/')[-1]])['properties']['version']['maximum'] = 2147483647

# Límite de uso donde se pueden extraer datos o generar credenciales
for oid in ('searchCustomers', 'getCustomer', 'createCustomer', 'createPasswordSetupLink', 'createEnrollmentCode'):
    o = OPS[oid]
    o['responses']['429'] = R('TooManyAttempts')
    o['responses'] = dict(sorted(o['responses'].items()))

# Las respuestas con secretos no se deben guardar en caché
for oid, code in [('createServiceIdentity', '201'), ('rotateServiceIdentityToken', '200'), ('createBranchMember', '201'), ('createPasswordSetupLink', '201'), ('getTableQr', '200'), ('rotateTableQr', '200')]:
    OPS[oid]['responses'][code]['headers']['Cache-Control'] = {'$ref': '#/components/headers/NoStore'}

# SEC-1: quien administra una sola sucursal no puede tomar la cuenta ni dar de baja a alguien con acceso a otras.
ALLB = 'Todas las sucursales donde el usuario objetivo tenga membresía activa'
for oid in ('createPasswordSetupLink', 'deactivateUser', 'activateUser'):
    OPS[oid]['x-restia']['branch'] = ALLB
    OPS[oid]['description'] = (OPS[oid].get('description', '') + ' El actor debe ser ADMIN en todas las sucursales del usuario objetivo; si no, responde 403.').strip()
for n in ('MemberCreated', 'PasswordSetupLink'):
    S[n]['properties']['setupUrl']['description'] = 'Se muestra una sola vez. Lleva el token en el fragmento de la URL, para que no llegue a logs ni a Referer'

yaml.dump(d, open(p, 'w', encoding='utf-8', newline='\n'), Dumper=NoAlias, allow_unicode=True, sort_keys=False, width=100)

edits = {
    'Decisiones_Modulos_Restantes.md': [
        ('`status`, `version`. **Índice:** `unique(email)`.', '`normalized_email`, `status`, `version`. **Índice:** `unique(normalized_email)`, el del documento maestro.'),
        ('- **`user_branch_memberships`:** `uuid`, `user_id`, `branch_id`, `role`, `active`, `version`. **Índice:** `partial unique(user_id, branch_id) where active=true`.',
         '- **`user_branch_memberships`:** `uuid`, `user_id`, `branch_id`, `role`, `status`, `version`. **Índice:** `unique(user_id, branch_id)`, el del documento maestro; volver a dar de alta reactiva el mismo documento, y `active` en la API se deriva de `status`.'),
        ('`anonymized_at`, `idempotency_key`, `version`. **Índices:** `(phone)`, no único, y `unique(idempotency_key)`, con la sucursal como parte de la clave.', '`anonymized_at`, `version`. **Índice:** `(phone)`, no único. La idempotencia usa `idempotency_records`.'),
    ],
    'Decisiones_IA.md': [('`(branch_id, customer.phone, status)`', '`(branch_id, customer_snapshot.phone, status)`')],
    'Decisiones_Checks.md': [('- **`check_allocations`:** `check_id`,', '- **`check_allocations`:** `branch_id`, `check_id`,')],
}
for f, lines in edits.items():
    t = open(f, encoding='utf-8', newline='').read()
    for a, c in lines:
        assert a in t, a
        t = t.replace(a, c)
    open(f, 'w', encoding='utf-8', newline='').write(t)
print('ok')
