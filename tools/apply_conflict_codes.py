"""Precisa en x-restia.conflictCodes qué códigos 409 produce cada operación (criterio 7 de la sección 36)."""
import yaml


class NoAlias(yaml.SafeDumper):
    def ignore_aliases(self, data):
        return True


p = 'openapi/restia-api.yaml'
d = yaml.safe_load(open(p, encoding='utf-8'))
R = d['components']['responses']
rid = '0b3c1f6e-8f7a-4a51-9a3e-2d1c7e5b9a10'


def examples(name):
    return R[name]['content']['application/json']['examples']


def add(name, code, msg):
    examples(name)[code] = {'value': {'error': {'code': code, 'message': msg, 'requestId': rid}}}


# Correcciones halladas al asignar códigos
# 1. La sección 30 reserva 422 para validación: estos casos no son conflictos de estado.
for code in ('PASSWORD_TOO_WEAK', 'AUDIT_RANGE_TOO_LARGE', 'CURRENT_PASSWORD_INVALID'):
    examples('ManagementConflict').pop(code, None)
for path in ('/branches/{branchId}/audit-log', '/audit-log'):
    d['paths'][path]['get']['responses'].pop('409', None)
d['paths']['/auth/password/change']['post']['responses'].pop('409', None)
# 2. Faltaban códigos para condiciones ya aprobadas.
add('PrintingConflict', 'SERVICE_IDENTITY_REVOKED', 'La identidad de servicio está revocada.')
add('PrintingConflict', 'PRINTER_INACTIVE', 'La impresora está inactiva.')
add('FiscalConflict', 'FISCAL_ISSUER_RFC_TAKEN', 'Ya existe un emisor con ese RFC.')
# 3. IA-7: con la IA apagada, disponibilidad y lookup también deben poder responder 409.
for path, m in (('/branches/{branchId}/reservations/availability', 'get'), ('/branches/{branchId}/reservations/lookup', 'post')):
    o = d['paths'][path][m]
    o['responses']['409'] = {'$ref': '#/components/responses/ReservationConflict'}
    o['responses'] = dict(sorted(o['responses'].items()))

# Específicos por operación; VERSION_CONFLICT e IDEMPOTENCY_KEY_REUSED se añaden por If-Match e Idempotency-Key.
M = {
    'enrollMobileDevice': ['DEVICE_ALREADY_ENROLLED'],
    'createBranch': ['BRANCH_CODE_TAKEN'],
    'updateBranch': ['BRANCH_HAS_ACTIVE_OPERATIONS'],
    'activateBranch': [],
    'deactivateBranch': ['BRANCH_HAS_ACTIVE_OPERATIONS'],
    'createTable': ['BRANCH_INACTIVE', 'TABLE_NUMBER_TAKEN'],
    'updateTable': ['TABLE_NUMBER_TAKEN'],
    'setTableStatus': ['TABLE_ALREADY_ASSIGNED'],
    'rotateTableQr': ['BRANCH_INACTIVE'],
    'openTableSession': ['BRANCH_INACTIVE', 'TABLE_NOT_AVAILABLE', 'TABLE_ALREADY_ASSIGNED', 'TABLE_RESERVED_SOON'],
    'updateTableSession': ['SESSION_NOT_ACTIVE'],
    'addTablesToSession': ['SESSION_NOT_ACTIVE', 'TABLE_NOT_AVAILABLE', 'TABLE_ALREADY_ASSIGNED'],
    'releaseTableFromSession': ['SESSION_NOT_ACTIVE', 'SESSION_LAST_TABLE'],
    'closeTableSession': ['SESSION_NOT_ACTIVE', 'SESSION_HAS_OPEN_CHECKS', 'SESSION_HAS_PENDING_UNITS'],
    'cancelTableSession': ['SESSION_NOT_ACTIVE', 'SESSION_HAS_ACTIVITY'],
    'confirmTableCleaning': ['CLEANING_CONFIRMATION_STALE'],
    'getReservationAvailability': ['RESERVATION_AI_DISABLED'],
    'lookupReservation': ['RESERVATION_AI_DISABLED'],
    'createReservation': ['BRANCH_INACTIVE', 'RESERVATION_AI_DISABLED', 'RESERVATION_REQUIRES_STAFF', 'RESERVATION_ACTIVE_LIMIT_EXCEEDED'],
    'updateReservation': ['RESERVATION_INVALID_STATE', 'RESERVATION_NOT_AVAILABLE', 'NO_TABLE_AVAILABLE'],
    'confirmReservation': ['RESERVATION_INVALID_STATE', 'RESERVATION_NOT_AVAILABLE', 'NO_TABLE_AVAILABLE', 'RESERVATION_AI_DISABLED'],
    'registerReservationArrival': ['RESERVATION_INVALID_STATE'],
    'checkInReservation': ['RESERVATION_INVALID_STATE', 'NO_TABLE_AVAILABLE', 'TABLE_ALREADY_ASSIGNED', 'TABLE_NOT_AVAILABLE', 'RESERVATION_PRIORITY_CONFLICT'],
    'cancelReservation': ['RESERVATION_INVALID_STATE', 'RESERVATION_AI_DISABLED'],
    'markReservationNoShow': ['RESERVATION_INVALID_STATE'],
    'createStation': ['BRANCH_INACTIVE', 'CATALOG_CODE_TAKEN'],
    'updateStation': [],
    'setStationStatus': ['STATION_IN_USE'],
    'createModifier': ['BRANCH_INACTIVE', 'CATALOG_CODE_TAKEN'],
    'updateModifier': [],
    'setModifierStatus': [],
    'createProduct': ['BRANCH_INACTIVE', 'CATALOG_CODE_TAKEN'],
    'updateProduct': [],
    'setProductStatus': ['PRODUCT_NOT_SELLABLE'],
    'replaceProductRoutes': ['PRODUCT_NOT_SELLABLE'],
    'publishProductRecipe': [],
    'createOrder': ['SESSION_NOT_ACTIVE', 'PRODUCT_UNAVAILABLE', 'CLIENT_ORDER_CONFLICT', 'CHECK_INVALID_STATE'],
    'updateOrderItemNotes': ['ORDER_UNIT_INVALID_STATE'],
    'transitionOrderUnits': ['VERSION_CONFLICT', 'ORDER_UNIT_INVALID_STATE', 'ORDER_UNIT_PAID', 'INVENTORY_INSUFFICIENT', 'INVENTORY_BLOCKED_FOOD_SAFETY', 'APPROVAL_INVALID'],
    'reassignOrderUnitStation': ['ORDER_UNIT_INVALID_STATE'],
    'createCheck': ['SESSION_NOT_ACTIVE'],
    'moveCheckUnits': ['CHECK_INVALID_STATE', 'CHECK_SESSION_MISMATCH', 'CHECK_HAS_CONFIRMED_PAYMENT', 'CHECK_HAS_OPEN_PAYMENT', 'CHECK_HAS_ACTIVE_INVOICE'],
    'requestCheck': ['CHECK_INVALID_STATE', 'CHECK_EMPTY'],
    'reopenCheck': ['CHECK_INVALID_STATE', 'CHECK_HAS_CONFIRMED_PAYMENT', 'CHECK_HAS_OPEN_PAYMENT', 'APPROVAL_INVALID'],
    'cancelCheck': ['CHECK_INVALID_STATE', 'CHECK_NOT_EMPTY'],
    'createCheckAdjustment': ['CHECK_INVALID_STATE', 'CHECK_HAS_CONFIRMED_PAYMENT', 'CHECK_HAS_OPEN_PAYMENT', 'CHECK_HAS_ACTIVE_INVOICE', 'ORDER_UNIT_INVALID_STATE', 'APPROVAL_INVALID'],
    'revertCheckAdjustment': ['CHECK_INVALID_STATE', 'CHECK_HAS_CONFIRMED_PAYMENT', 'CHECK_HAS_OPEN_PAYMENT', 'CHECK_HAS_ACTIVE_INVOICE', 'APPROVAL_INVALID'],
    'createPayment': ['CASH_SHIFT_NOT_OPEN', 'CHECK_INVALID_STATE', 'PAYMENT_REQUIRES_RECONCILIATION', 'PAYMENT_EXCEEDS_BALANCE'],
    'recordPaymentResult': ['PAYMENT_INVALID_STATE'],
    'reconcilePayment': ['PAYMENT_INVALID_STATE'],
    'createRefund': ['PAYMENT_NOT_REFUNDABLE', 'REFUND_ALREADY_EXISTS', 'CASH_SHIFT_NOT_OPEN'],
    'recordRefundResult': ['REFUND_INVALID_STATE'],
    'reconcileRefund': ['REFUND_INVALID_STATE'],
    'createIngredient': ['BRANCH_INACTIVE', 'CATALOG_CODE_TAKEN'],
    'updateIngredient': [],
    'setIngredientStatus': ['INGREDIENT_IN_USE'],
    'createInventoryMovement': ['BRANCH_INACTIVE'],
    'createInventoryReceipt': ['BRANCH_INACTIVE'],
    'createInventoryCount': ['INVENTORY_COUNT_ALREADY_OPEN'],
    'replaceInventoryCountLines': ['INVENTORY_COUNT_INVALID_STATE'],
    'applyInventoryCount': ['INVENTORY_COUNT_INVALID_STATE'],
    'cancelInventoryCount': ['INVENTORY_COUNT_INVALID_STATE'],
    'createCashRegister': ['BRANCH_INACTIVE', 'CATALOG_CODE_TAKEN'],
    'updateCashRegister': [],
    'setCashRegisterStatus': ['CASH_REGISTER_HAS_ACTIVE_SHIFT'],
    'openCashShift': ['BRANCH_INACTIVE', 'CASH_REGISTER_INACTIVE', 'CASH_SHIFT_ALREADY_OPEN', 'CASH_SHIFT_REASON_REQUIRED'],
    'createCashMovement': ['CASH_SHIFT_NOT_OPEN'],
    'startCashShiftClosing': ['CASH_SHIFT_INVALID_STATE'],
    'cancelCashShiftClosing': ['CASH_SHIFT_INVALID_STATE'],
    'closeCashShift': ['CASH_SHIFT_INVALID_STATE', 'CASH_SHIFT_HAS_UNRESOLVED_OPERATIONS', 'CASH_SHIFT_REASON_REQUIRED'],
    'createFiscalIssuer': ['FISCAL_ISSUER_RFC_TAKEN'],
    'updateFiscalIssuer': [],
    'setFiscalIssuerStatus': ['FISCAL_ISSUER_NOT_CONFIGURED'],
    'uploadFiscalIssuerCsd': ['CSD_INVALID', 'FISCAL_OPERATION_FAILED'],
    'assignBranchFiscalIssuer': ['FISCAL_ISSUER_NOT_CONFIGURED'],
    'createInvoice': ['FISCAL_ISSUER_NOT_CONFIGURED', 'INVOICE_CHECK_NOT_ELIGIBLE', 'INVOICE_CHECK_ALREADY_INVOICED', 'INVOICE_CHECK_IN_STAMPED_GLOBAL'],
    'updateInvoice': ['INVOICE_INVALID_STATE'],
    'discardInvoice': ['INVOICE_INVALID_STATE'],
    'stampInvoice': ['INVOICE_INVALID_STATE', 'FISCAL_STATUS_UNKNOWN'],
    'reconcileInvoice': ['INVOICE_INVALID_STATE', 'FISCAL_STATUS_UNKNOWN'],
    'cancelInvoice': ['INVOICE_INVALID_STATE', 'INVOICE_REPLACEMENT_REQUIRED'],
    'createCreditNote': ['INVOICE_INVALID_STATE', 'CREDIT_NOTE_EXCEEDS_AVAILABLE'],
    'downloadInvoiceXml': ['INVOICE_INVALID_STATE'],
    'downloadInvoicePdf': ['INVOICE_INVALID_STATE'],
    'retryPaymentComplement': ['COMPLEMENT_INVALID_STATE'],
    'reconcilePaymentComplement': ['COMPLEMENT_INVALID_STATE', 'FISCAL_STATUS_UNKNOWN'],
    'createInvoiceBatch': ['FISCAL_ISSUER_NOT_CONFIGURED', 'INVOICE_BATCH_EMPTY'],
    'excludeChecksFromBatch': ['INVOICE_INVALID_STATE'],
    'createServiceIdentity': ['SERVICE_IDENTITY_NAME_TAKEN'],
    'rotateServiceIdentityToken': ['SERVICE_IDENTITY_REVOKED'],
    'revokeServiceIdentity': ['SERVICE_IDENTITY_REVOKED'],
    'updateServiceIdentity': ['SERVICE_IDENTITY_REVOKED'],
    'createPrinter': ['CATALOG_CODE_TAKEN', 'PRINT_AGENT_INACTIVE'],
    'updatePrinter': ['PRINT_AGENT_INACTIVE'],
    'setPrinterStatus': ['PRINTER_HAS_ACTIVE_JOBS'],
    'createPrintStation': ['CATALOG_CODE_TAKEN', 'PRINTER_INACTIVE'],
    'updatePrintStation': ['PRINTER_INACTIVE'],
    'setPrintStationStatus': [],
    'createPrintAgent': ['SERVICE_IDENTITY_REVOKED'],
    'setPrintAgentStatus': [],
    'createPrintJob': ['PRINT_ROUTE_NOT_CONFIGURED'],
    'retryPrintJob': ['PRINT_JOB_INVALID_STATE', 'PRINT_JOB_UNKNOWN'],
    'resolvePrintJob': ['PRINT_JOB_INVALID_STATE'],
    'cancelPrintJob': ['PRINT_JOB_INVALID_STATE'],
    'reprintPrintJob': ['PRINT_JOB_INVALID_STATE'],
    'startPrintAttempt': ['PRINT_JOB_LEASE_LOST'],
    'reportPrintAttemptResult': ['PRINT_JOB_LEASE_LOST'],
    'resetPassword': ['PASSWORD_TOKEN_INVALID'],
    'createPasswordSetupLink': ['USER_INACTIVE'],
    'deactivateUser': ['LAST_BRANCH_ADMIN'],
    'activateUser': [],
    'createBranchMember': ['BRANCH_INACTIVE', 'MEMBERSHIP_ALREADY_EXISTS'],
    'updateBranchMember': ['LAST_BRANCH_ADMIN', 'USER_INACTIVE'],
    'revokeBranchMember': ['LAST_BRANCH_ADMIN'],
    'createCustomer': ['BRANCH_INACTIVE'],
    'updateCustomer': ['CUSTOMER_ANONYMIZED'],
    'anonymizeCustomer': ['CUSTOMER_ANONYMIZED'],
    'createReportRebuild': ['REPORT_REBUILD_IN_PROGRESS'],
}
used = set()
for pa, v in d['paths'].items():
    for m, o in v.items():
        if not isinstance(o, dict) or 'responses' not in o or '409' not in o['responses']:
            continue
        oid = o['operationId']
        assert oid in M, 'sin asignar: ' + oid
        ps = str(o.get('parameters'))
        cs = list(M[oid])
        if 'parameters/IfMatch' in ps and 'VERSION_CONFLICT' not in cs:
            cs.insert(0, 'VERSION_CONFLICT')
        if 'parameters/IdempotencyKey' in ps:
            cs.insert(0, 'IDEMPOTENCY_KEY_REUSED')
        o['x-restia']['conflictCodes'] = cs
        name = o['responses']['409']['$ref'].split('/')[-1]
        c = R[name]['content']['application/json']
        ex = c['examples'] if 'examples' in c else {'x': {'value': c['example']}}
        known = {e['value']['error']['code'] for e in ex.values()}
        assert set(cs) <= known, (oid, set(cs) - known)
        assert cs, 'sin códigos: ' + oid
        used |= {(name, x) for x in cs}
for name, r in R.items():
    c = r.get('content', {}).get('application/json', {})
    if 'examples' in c:
        for e in c['examples'].values():
            code = e['value']['error']['code']
            if name.endswith('Conflict') and (name, code) not in used:
                print('sin operación:', name, code)
yaml.dump(d, open(p, 'w', encoding='utf-8', newline='\n'), Dumper=NoAlias, allow_unicode=True, sort_keys=False, width=100)
print('ok', len(M))
