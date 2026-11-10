#!/usr/bin/env python3
"""Criterio de salida de la fase 5 contra una API real: abrir mesa, comandar, cobrar y cerrar caja, con choques.

Habla con la API por HTTP, como la app, y lanza los choques en paralelo contra varios servidores
(`php artisan serve` atiende una petición a la vez, así que cada puerto es un proceso). En cada choque
exige que gane una sola petición y que las demás respondan con su código; al final, que cuenten igual
las cuentas, los pagos y la caja. Un 503 SERVICE_BUSY se reintenta con la misma clave, como la app.

Requisitos: sucursal de prueba cargada con restia:install, restia:demo --staff, restia:demo-printing,
y RESTIA_DEMO_PASSWORD en el .env. Cierra el turno abierto de la caja, abre uno limpio para el
escenario y deja otro abierto al terminar. No corre contra producción: solo acepta 127.0.0.1.

    python tools/escenario_fase5.py --ports 8001,8002,8003 --php C:/ruta/php.exe
"""

import argparse
import json
import os
import random
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request
import uuid
from concurrent.futures import ThreadPoolExecutor
from decimal import Decimal

API_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FAILURES = []


def ok(cond, msg):
    print(('  ✓ ' if cond else '  ✗ ') + msg)
    if not cond:
        FAILURES.append(msg)
    return cond


def section(title):
    print('\n' + title)


def money(value):
    return Decimal(value['amount'] if isinstance(value, dict) else value)


def mx(value):
    return {'amount': f'{Decimal(value):.2f}', 'currency': 'MXN'}


class Response:
    def __init__(self, status, body, headers):
        self.status = status
        self.body = body or {}
        self.headers = headers

    @property
    def data(self):
        return self.body.get('data')

    @property
    def code(self):
        return (self.body.get('error') or {}).get('code')

    def __repr__(self):
        return f'{self.status} {self.code or ""}'.strip()


class Client:
    """Un empleado con su propio dispositivo y su token, como un teléfono con la app."""

    def __init__(self, bases, name):
        self.bases = bases
        self.name = name
        self.token = None
        self.turn = 0
        self.lock = threading.Lock()

    def base(self, server):
        if server is not None:
            return self.bases[server % len(self.bases)]
        with self.lock:
            self.turn += 1
            return self.bases[self.turn % len(self.bases)]

    def call(self, method, path, body=None, key=None, if_match=None, server=None):
        url = self.base(server) + path
        headers = {'Accept': 'application/json', 'Content-Type': 'application/json'}
        if self.token:
            headers['Authorization'] = 'Bearer ' + self.token
        if key:
            headers['Idempotency-Key'] = key
        if if_match is not None:
            headers['If-Match'] = f'"{if_match}"'
        data = None if body is None else json.dumps(body).encode()
        for attempt in range(6):
            request = urllib.request.Request(url, data=data, headers=headers, method=method)
            try:
                with urllib.request.urlopen(request, timeout=30) as r:
                    raw = r.read()
                    response = Response(r.status, json.loads(raw) if raw else {}, dict(r.headers))
            except urllib.error.HTTPError as e:
                raw = e.read()
                try:
                    parsed = json.loads(raw) if raw else {}
                except ValueError:
                    parsed = {'error': {'code': 'NO_JSON', 'message': raw[:200].decode('utf-8', 'replace')}}
                response = Response(e.code, parsed, dict(e.headers))
            # Transacción agotada: se reintenta igual que la app, con la misma clave.
            if response.status == 503 and response.code == 'SERVICE_BUSY' and attempt < 5:
                time.sleep(min(float(response.headers.get('Retry-After', '1')), 1.0) * (attempt + 1) / 3)
                continue
            return response
        return response


def at_once(*calls):
    """Lanza las llamadas a la vez, cada una a un servidor distinto, y devuelve sus respuestas en orden."""
    barrier = threading.Barrier(len(calls))

    def run(index):
        barrier.wait()
        return calls[index](index)

    with ThreadPoolExecutor(len(calls)) as pool:
        return list(pool.map(run, range(len(calls))))


def key():
    return str(uuid.uuid4())


def env_value(name):
    with open(os.path.join(API_DIR, '.env'), encoding='utf-8') as f:
        for line in f:
            if line.startswith(name + '='):
                return line.split('=', 1)[1].strip().strip('"').strip("'")
    return os.environ.get(name, '')


class Scenario:
    def __init__(self, bases, php, branch_code):
        self.bases = bases
        self.php = php
        self.branch_code = branch_code
        self.mesero = Client(bases, 'mesero')
        self.cocina = Client(bases, 'cocina')
        self.gerente = Client(bases, 'gerente')

    # --- Preparación ---

    def enrollment_code(self):
        out = subprocess.run([self.php, 'artisan', 'restia:demo-mobile', '--branch=' + self.branch_code],
                             cwd=API_DIR, capture_output=True, text=True, encoding='utf-8', check=True).stdout
        return out.strip().splitlines()[-1].strip()

    def login(self, client, email, password):
        device = str(uuid.uuid4())
        enrolled = client.call('POST', 'auth/mobile/enroll',
                               {'code': self.enrollment_code(), 'deviceUuid': device, 'displayName': 'Escenario ' + client.name})
        assert enrolled.status == 201, f'alta de {client.name}: {enrolled} {enrolled.body}'
        logged = client.call('POST', 'auth/mobile/login', {
            'email': email, 'password': password, 'deviceUuid': device, 'deviceCredential': enrolled.data['deviceCredential']})
        assert logged.status == 201, f'login de {client.name}: {logged} {logged.body}'
        client.token = logged.data['accessToken']

    def setup(self):
        password = env_value('RESTIA_DEMO_PASSWORD')
        assert len(password) >= 12, 'falta RESTIA_DEMO_PASSWORD en el .env'
        for client in (self.mesero, self.cocina, self.gerente):
            self.login(client, client.name + '@demo.mx', password)
        me = self.gerente.call('GET', 'auth/me').data
        self.b = 'branches/' + next(m['branch']['id'] for m in me['memberships']) + '/'
        menu = self.mesero.call('GET', self.b + 'menu').data
        required = {m['id'] for m in menu['modifiers'] if m['minSelections'] > 0}
        # Productos que se piden sin elegir modificadores, para que el escenario no dependa del menú.
        self.products = [p for p in menu['products'] if p['availability'] == 'AVAILABLE' and not required & set(p['modifierIds'])]
        assert len(self.products) >= 2, 'el menú necesita dos productos disponibles sin modificadores obligatorios'

    # --- Piezas del servicio ---

    def free_tables(self):
        rows = self.mesero.call('GET', self.b + 'tables?perPage=100').data
        return [t for t in rows if t['status'] == 'ACTIVE' and t['operationalState'] == 'LIBRE']

    def open_table(self, table):
        r = self.mesero.call('POST', self.b + 'table-sessions', {'tableIds': [table['id']], 'guestCount': 2}, key=key())
        assert r.status == 201, f'abrir mesa {table["number"]}: {r} {r.body}'
        return r.data['id']

    def order_body(self, session):
        return {'sessionId': session, 'clientOrderUuid': key(), 'items': [
            {'productId': self.products[0]['id'], 'quantity': 2}, {'productId': self.products[1]['id'], 'quantity': 1}]}

    def order(self, session):
        r = self.mesero.call('POST', self.b + 'orders', self.order_body(session), key=key())
        assert r.status == 201, f'comanda: {r} {r.body}'

    def units(self, session, status=None):
        # Sin estado, el backend devuelve solo lo pendiente, en preparación y listo.
        query = f'order-units?sessionId={session}&perPage=100' + (f'&status={status}' if status else '')
        return [u['unit'] for u in self.cocina.call('GET', self.b + query).data]

    def transition(self, client, action, units, reason=None, server=None, idem=None):
        body = {'action': action, 'units': [{'id': u['id'], 'version': u['version']} for u in units]}
        if reason:
            body['reason'] = reason
        return client.call('POST', self.b + 'order-units/transitions', body, key=idem or key(), server=server)

    def serve(self, session):
        """Cocina empieza y termina todo, y el mesero lo entrega."""
        for client, action, status in ((self.cocina, 'START', 'PENDING'), (self.cocina, 'READY', 'PREPARING'), (self.mesero, 'DELIVER', 'READY')):
            pending = self.units(session, status)
            if pending:
                r = self.transition(client, action, pending)
                assert r.status == 200, f'{action}: {r} {r.body}'

    def check(self, session):
        return self.mesero.call('GET', self.b + f'checks?sessionId={session}').data[0]

    def pay_body(self, check, method, amount, tip='0', tendered=None):
        body = {'checkId': check['id'], 'cashShiftId': self.shift, 'method': method, 'amount': mx(amount)}
        if Decimal(tip) > 0:
            body['tip'] = mx(tip)
        if tendered is not None:
            body['amountTendered'] = mx(tendered)
        return body

    def close_and_clean(self, session):
        version = self.mesero.call('GET', self.b + f'table-sessions/{session}').data['version']
        r = self.mesero.call('POST', self.b + f'table-sessions/{session}/close', if_match=version)
        assert r.status == 200, f'cerrar sesión: {r} {r.body}'
        for table in r.data['tables']:
            done = self.mesero.call('POST', self.b + f'table-sessions/{session}/tables/{table["tableId"]}/cleaning-confirmation',
                                    {'membershipVersion': table['cleaningMembershipVersion']})
            assert done.status == 200, f'limpieza: {done} {done.body}'
        return self.mesero.call('GET', self.b + f'table-sessions/{session}').data['status']

    def print_job(self, client, kind, source):
        return client.call('POST', self.b + 'print-jobs', {'type': kind, 'sourceId': source}, key=key())

    # --- Caja ---

    def active_shift(self):
        rows = self.gerente.call('GET', self.b + 'cash-shifts?perPage=100').data
        return next((s for s in rows if s['status'] in ('OPEN', 'CLOSING')), None)

    def close_shift(self, shift):
        """Cuenta lo esperado y deja el fondo en el cajón: sin diferencia."""
        if shift['status'] == 'OPEN':
            r = self.gerente.call('POST', self.b + f'cash-shifts/{shift["id"]}/start-closing', if_match=shift['version'])
            assert r.status == 200, f'iniciar corte: {r} {r.body}'
            shift = r.data
        summary = self.gerente.call('GET', self.b + f'cash-shifts/{shift["id"]}/summary').data
        expected = money(summary['expectedCash'])
        left = min(expected, money(summary['openingAmount']))
        r = self.gerente.call('POST', self.b + f'cash-shifts/{shift["id"]}/close',
                              {'countedCash': mx(expected), 'cashLeftInDrawer': mx(left)}, key=key(), if_match=shift['version'])
        assert r.status == 200, f'cerrar turno: {r} {r.body}'
        return r.data, summary, left

    def open_shift(self, register, opening):
        r = self.gerente.call('POST', self.b + 'cash-shifts', {'cashRegisterId': register, 'openingAmount': mx(opening)}, key=key())
        assert r.status == 201, f'abrir turno: {r} {r.body}'
        return r.data

    # --- Escenario ---

    def run(self):
        section('Preparación')
        self.setup()
        previous = self.active_shift()
        if previous:
            _, _, left = self.close_shift(previous)
            register = previous['cashRegisterId']
        else:
            register = self.gerente.call('GET', self.b + 'cash-registers').data[0]['id']
            left = Decimal('500')
        shift = self.open_shift(register, left)
        self.shift = shift['id']
        tables = self.free_tables()
        assert len(tables) >= 3, 'hacen falta 3 mesas libres'
        print(f'  turno nuevo con fondo de ${left:.2f}; mesas {tables[0]["number"]}, {tables[1]["number"]} y {tables[2]["number"]}')

        section('Choque 1: dos empleados abren la misma mesa')
        body = {'tableIds': [tables[0]['id']], 'guestCount': 2}
        results = at_once(lambda s: self.mesero.call('POST', self.b + 'table-sessions', body, key=key(), server=s),
                          lambda s: self.gerente.call('POST', self.b + 'table-sessions', body, key=key(), server=s))
        won = [r for r in results if r.status == 201]
        ok(len(won) == 1, f'gana una sola apertura {results}')
        ok(all(r.code in ('TABLE_NOT_AVAILABLE', 'TABLE_ALREADY_ASSIGNED') for r in results if r.status != 201), 'la otra recibe un 409 de mesa ocupada')
        s1 = won[0].data['id']

        section('Choque 2: la misma comanda llega tres veces a la vez')
        order, idem = self.order_body(s1), key()
        results = at_once(*[lambda s: self.mesero.call('POST', self.b + 'orders', order, key=idem, server=s)] * 3)
        ok(all(r.status == 201 for r in results), f'las tres responden 201 {results}')
        ok(len({r.data['id'] for r in results if r.data}) == 1, 'todas devuelven la misma comanda')
        ok(len(self.mesero.call('GET', self.b + f'orders?sessionId={s1}').data) == 1, 'la sesión tiene una sola comanda')

        section('Choque 3: cocina empieza y el mesero cancela la misma unidad')
        unit = self.units(s1, 'PENDING')[0]
        results = at_once(lambda s: self.transition(self.cocina, 'START', [unit], server=s),
                          lambda s: self.transition(self.mesero, 'CANCEL', [unit], reason='Choque de prueba', server=s))
        won = [i for i, r in enumerate(results) if r.status == 200]
        ok(len(won) == 1, f'gana una sola transición {results}')
        ok(all(r.code == 'ORDER_UNIT_INVALID_STATE' or r.code == 'VERSION_CONFLICT' for r in results if r.status != 200), 'la otra recibe un 409')
        final = next((u['status'] for u in self.units(s1) + self.units(s1, 'CANCELLED') if u['id'] == unit['id']), None)
        ok(final == ('PREPARING' if won == [0] else 'CANCELLED'), f'la unidad queda como dijo la ganadora ({final})')
        self.serve(s1)

        section('Choque 4: dos cobros completos de la misma cuenta a la vez')
        check = self.check(s1)
        balance = money(check['balance'])
        results = at_once(
            lambda s: self.mesero.call('POST', self.b + 'payments', self.pay_body(check, 'CASH', balance, tendered=balance), key=key(), server=s),
            lambda s: self.gerente.call('POST', self.b + 'payments', self.pay_body(check, 'CASH', balance, tendered=balance), key=key(), server=s))
        ok(sum(r.status == 201 for r in results) == 1, f'entra un solo cobro {results}')
        ok(all(r.code in ('PAYMENT_EXCEEDS_BALANCE', 'CHECK_INVALID_STATE') for r in results if r.status != 201), 'el otro recibe un 409')
        check = self.check(s1)
        ok(check['status'] == 'PAID' and money(check['paidTotal']) == money(check['total']), f'la cuenta queda PAID por ${money(check["total"])}')

        section('Choque 5: cobro con tarjeta repetido a la vez, con la misma clave')
        s2 = self.open_table(tables[1])
        self.order(s2)
        self.serve(s2)
        check = self.check(s2)
        half = (money(check['balance']) / 2).quantize(Decimal('0.01'))
        body, idem = self.pay_body(check, 'CARD_CREDIT', half, tip='15'), key()
        results = at_once(*[lambda s: self.mesero.call('POST', self.b + 'payments', body, key=idem, server=s)] * 2)
        ok(all(r.status == 201 for r in results) and results[0].data['id'] == results[1].data['id'], f'las dos devuelven el mismo pago {results}')
        payments = self.mesero.call('GET', self.b + f'payments?checkId={check["id"]}').data
        ok(len(payments) == 1, 'la cuenta tiene un solo pago')
        card = payments[0]
        r = self.mesero.call('POST', self.b + f'payments/{card["id"]}/result',
                             {'outcome': 'CONFIRMED', 'reference': 'ESC-' + card['id'][:6], 'card': {'brand': 'VISA'}}, key=key(), if_match=card['version'])
        ok(r.status == 200 and r.data['status'] == 'CONFIRMED', 'la terminal lo confirma')
        rest = money(self.check(s2)['balance'])
        r = self.mesero.call('POST', self.b + 'payments', self.pay_body(check, 'CASH', rest, tip='10', tendered=rest + 100), key=key())
        ok(r.status == 201 and money(r.data['change']) == Decimal('90.00'), 'el resto en efectivo da $90.00 de cambio')
        ok(self.check(s2)['status'] == 'PAID', 'la cuenta queda PAID')
        printed = [self.print_job(self.mesero, 'PRE_CHECK', check['id']), self.print_job(self.mesero, 'PAYMENT_RECEIPT', card['id'])]
        ok(all(p.status == 201 for p in printed), f'se piden la precuenta y el recibo {printed}')

        section('Choque 6: dos confirmaciones de limpieza a la vez')
        version = self.mesero.call('GET', self.b + f'table-sessions/{s1}').data['version']
        closed = self.mesero.call('POST', self.b + f'table-sessions/{s1}/close', if_match=version)
        ok(closed.status == 200 and closed.data['status'] == 'PENDING_CLEANING', 'la primera mesa queda por limpiar')
        seat = closed.data['tables'][0]
        path = self.b + f'table-sessions/{s1}/tables/{seat["tableId"]}/cleaning-confirmation'
        body = {'membershipVersion': seat['cleaningMembershipVersion']}
        results = at_once(lambda s: self.mesero.call('POST', path, body, server=s), lambda s: self.gerente.call('POST', path, body, server=s))
        ok(all(r.status == 200 for r in results), f'las dos responden 200 {results}')
        ok(self.mesero.call('GET', self.b + f'table-sessions/{s1}').data['status'] == 'CLOSED', 'la sesión queda CLOSED')
        ok(self.close_and_clean(s2) == 'CLOSED', 'la segunda mesa se cierra y se limpia')

        section('Choque 7: un cobro llega mientras el gerente inicia el corte')
        s3 = self.open_table(tables[2])
        self.order(s3)
        self.serve(s3)
        check = self.check(s3)
        balance = money(check['balance'])
        current = self.gerente.call('GET', self.b + f'cash-shifts/{self.shift}').data
        results = at_once(
            lambda s: self.mesero.call('POST', self.b + 'payments', self.pay_body(check, 'CASH', balance, tendered=balance), key=key(), server=s),
            # El cobro hace más trabajo: sin un retraso al azar, el corte casi siempre gana y el otro orden no se prueba.
            lambda s: time.sleep(random.uniform(0, 0.15)) or self.gerente.call(
                'POST', self.b + f'cash-shifts/{self.shift}/start-closing', if_match=current['version'], server=s))
        paid, closing = results
        ok(closing.status == 200, f'el corte inicia {closing}')
        ok(paid.status == 201 or paid.code == 'CASH_SHIFT_NOT_OPEN', f'el cobro entra antes o se rechaza por turno cerrado ({paid})')
        print(f'  · esta vez {"el cobro llegó primero" if paid.status == 201 else "el corte llegó primero"}')

        section('Cierre de caja: lo esperado coincide con los pagos')
        summary = self.gerente.call('GET', self.b + f'cash-shifts/{self.shift}/summary').data
        rows = self.gerente.call('GET', self.b + f'payments?cashShiftId={self.shift}&perPage=100').data
        confirmed = [p for p in rows if p['status'] == 'CONFIRMED']
        for method in ('CASH', 'CARD_CREDIT'):
            mine = [p for p in confirmed if p['method'] == method]
            line = next(m for m in summary['byMethod'] if m['method'] == method)
            ok(money(line['sales']) == sum((money(p['amount']) for p in mine), Decimal(0)) and
               money(line['tips']) == sum((money(p['tip']) for p in mine), Decimal(0)),
               f'{method}: ventas ${money(line["sales"])} y propinas ${money(line["tips"])} = suma de sus pagos')
        cash = next(m for m in summary['byMethod'] if m['method'] == 'CASH')
        ok(money(summary['expectedCash']) == money(summary['openingAmount']) + money(cash['sales']) + money(cash['tips']),
           f'efectivo esperado ${money(summary["expectedCash"])} = fondo + ventas + propinas en efectivo')
        result, _, left = self.close_shift(self.gerente.call('GET', self.b + f'cash-shifts/{self.shift}').data)
        ok(result['status'] == 'CLOSED' and money(result['difference']) == 0, 'el turno cierra sin diferencia')
        report = self.print_job(self.gerente, 'CASH_SHIFT_REPORT', self.shift)
        ok(report.status == 201, f'se pide el reporte de caja ({report})')
        ok(self.print_job(self.mesero, 'CASH_SHIFT_REPORT', self.shift).status == 403, 'el mesero no puede pedir el reporte')

        section('Después del corte')
        self.shift = self.open_shift(register, left)['id']
        if paid.status != 201:
            r = self.mesero.call('POST', self.b + 'payments', self.pay_body(check, 'CASH', balance, tendered=balance), key=key())
            ok(r.status == 201, 'el cobro rechazado entra en el turno nuevo')
        ok(self.close_and_clean(s3) == 'CLOSED', 'la tercera mesa se cierra y se limpia')
        print(f'  turno nuevo abierto con ${left:.2f} para seguir probando')


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--ports', default='8001,8002,8003', help='Puertos de los servidores locales, separados por coma')
    parser.add_argument('--php', default='php', help='Ejecutable de PHP, para pedir los códigos de alta')
    parser.add_argument('--branch', default='CENTRO', help='Código de la sucursal de prueba')
    args = parser.parse_args()
    bases = [f'http://127.0.0.1:{p.strip()}/api/v1/' for p in args.ports.split(',')]
    sys.stdout.reconfigure(encoding='utf-8')
    try:
        Scenario(bases, args.php, args.branch).run()
    except AssertionError as e:
        print(f'\nEl escenario se detuvo: {e}')
        return 2
    print('\n' + ('Fase 5: todo cuadra.' if not FAILURES else f'Fase 5: {len(FAILURES)} comprobación(es) fallaron.'))
    return 1 if FAILURES else 0


if __name__ == '__main__':
    sys.exit(main())
