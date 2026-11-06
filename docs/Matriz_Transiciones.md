# Matriz de transiciones

**Estado:** consolidada desde las decisiones aprobadas
**Fecha:** 2026-11-02

Reúne las tablas de estados de cada módulo. Ante una diferencia, manda la decisión del módulo.

## Tables/Sessions

### 2. Máquina de estados de la sesión

| Evento | Estado previo | Validaciones | Documentos modificados | Estado resultante | Errores |
|---|---|---|---|---|---|
| Abrir | Ninguno | Sucursal activa; mesas `ACTIVE` sin asignación activa | `table_sessions`, `table_assignments`, `branches.operations_epoch`, `audit_log`, `outbox_events` | `ACTIVE` | `BRANCH_INACTIVE`, `TABLE_NOT_AVAILABLE`, `TABLE_ALREADY_ASSIGNED`, `IDEMPOTENCY_KEY_REUSED` |
| Agregar mesa | `ACTIVE` | `If-Match`; mesa disponible | Sesión (`membership_version` +1), asignación, `branches.operations_epoch`, auditoría, outbox | `ACTIVE` | `VERSION_CONFLICT`, `SESSION_NOT_ACTIVE`, `TABLE_ALREADY_ASSIGNED`, `TABLE_NOT_AVAILABLE` |
| Separar mesa | `ACTIVE` | `If-Match`; no es la última mesa | Sesión (`membership_version` +1), asignación, limpieza si aplica, auditoría, outbox | `ACTIVE` | `VERSION_CONFLICT`, `SESSION_NOT_ACTIVE`, `SESSION_LAST_TABLE` |
| Cerrar | `ACTIVE` | `If-Match`; requisitos de T-4 | Sesión, asignaciones (`phase = CLEANING`), limpieza `PENDING` por mesa, auditoría, outbox | `PENDING_CLEANING` | `VERSION_CONFLICT`, `SESSION_NOT_ACTIVE`, `SESSION_HAS_OPEN_CHECKS`, `SESSION_HAS_PENDING_UNITS` |
| Confirmar limpieza | `ACTIVE` o `PENDING_CLEANING` | Existe limpieza `PENDING` con esa versión | Limpieza `CONFIRMED`, asignación (`active = false`), sesión (`version` +1), auditoría, outbox | `CLOSED` si no quedan mesas; si no, sin cambio | `CLEANING_CONFIRMATION_STALE` |
| Cancelar | `ACTIVE` | `If-Match`; motivo; sin unidades, pagos ni mesas en limpieza | Sesión, asignaciones (`active = false`), auditoría, outbox | `CANCELLED` | `VERSION_CONFLICT`, `SESSION_NOT_ACTIVE`, `SESSION_HAS_ACTIVITY` |

## Reservations

### 2. Máquina de estados

| Evento | Estado previo | Validaciones | Documentos modificados | Estado resultante | Errores |
|---|---|---|---|---|---|
| Crear | Ninguno | Datos e intervalo válidos; sucursal activa | `reservations`, auditoría, outbox | `PENDING` | `BRANCH_INACTIVE`, `IDEMPOTENCY_KEY_REUSED` |
| Confirmar | `PENDING` | Horario y mesas disponibles, revalidados en la transacción | Reserva (`planned_table_ids`), `branches.operations_epoch`, auditoría, outbox | `CONFIRMED` | `VERSION_CONFLICT`, `RESERVATION_INVALID_STATE`, `RESERVATION_NOT_AVAILABLE` |
| Editar horario o personas | `PENDING`, `CONFIRMED` | Si está `CONFIRMED`, revalida; si falla, conserva lo anterior | Reserva, `operations_epoch`, auditoría, outbox | Sin cambio | `VERSION_CONFLICT`, `RESERVATION_INVALID_STATE`, `RESERVATION_NOT_AVAILABLE` |
| Registrar llegada | `CONFIRMED` | Cliente presente | Reserva (`arrival_registered_at`), auditoría, outbox | `ARRIVED_WAITING` | `VERSION_CONFLICT`, `RESERVATION_INVALID_STATE` |
| Sentar | `CONFIRMED`, `ARRIVED_WAITING` | Mesas disponibles y compatibles; prioridad (R-8) | Reserva, `table_sessions`, `table_assignments`, `operations_epoch`, auditoría, outbox | `CHECKED_IN` | `VERSION_CONFLICT`, `RESERVATION_INVALID_STATE`, `NO_TABLE_AVAILABLE`, `TABLE_ALREADY_ASSIGNED`, `RESERVATION_PRIORITY_CONFLICT` |
| Cancelar | `PENDING`, `CONFIRMED`, `ARRIVED_WAITING` | Motivo | Reserva, auditoría, outbox | `CANCELLED` | `VERSION_CONFLICT`, `RESERVATION_INVALID_STATE` |
| Marcar `NO_SHOW` | `CONFIRMED` | Pasó la tolerancia; sin llegada registrada | Reserva, auditoría, outbox | `NO_SHOW` | `VERSION_CONFLICT`, `RESERVATION_INVALID_STATE` |
| Vencer (sistema) | `PENDING` | Llegó `start_at` | Reserva, auditoría, outbox | `CANCELLED` | Ninguno |
| Sesión pasa a `PENDING_CLEANING` | `CHECKED_IN` | Sesión vinculada | Reserva, auditoría, outbox | `COMPLETED` | Ninguno |
| Sesión cancelada | `CHECKED_IN` | Sesión vinculada | Reserva, auditoría, outbox | `ARRIVED_WAITING` | Ninguno |

## Orders

### 3. Máquina de estados de la unidad

| Evento | Estado previo | Validaciones | Documentos modificados | Estado resultante | Errores |
|---|---|---|---|---|---|
| Crear comanda | Ninguno | Sesión `ACTIVE`; producto activo y disponible; modificadores válidos; estación activa | `orders`, `order_items`, `order_item_units`, `checks`, `check_allocations`, sesión, auditoría, outbox | `PENDING` | `SESSION_NOT_ACTIVE`, `PRODUCT_UNAVAILABLE`, `CLIENT_ORDER_CONFLICT`, `IDEMPOTENCY_KEY_REUSED` |
| `START` | `PENDING` | Versión; stock o token de override; sin bloqueo alimentario | Unidad (`recipe_version`), `inventory_movements`, `ingredients`, resumen de `orders`, auditoría, outbox | `PREPARING` | `VERSION_CONFLICT`, `ORDER_UNIT_INVALID_STATE`, `INVENTORY_INSUFFICIENT`, `INVENTORY_BLOCKED_FOOD_SAFETY`, `APPROVAL_INVALID` |
| `READY` | `PREPARING` | Versión | Unidad, resumen, auditoría, outbox | `READY` | `VERSION_CONFLICT`, `ORDER_UNIT_INVALID_STATE` |
| `DELIVER` | `READY` | Versión | Unidad, resumen, auditoría, outbox | `DELIVERED` | `VERSION_CONFLICT`, `ORDER_UNIT_INVALID_STATE` |
| `CANCEL` | `PENDING` | Versión; motivo; sin pago | Unidad, `check_allocations` (`active = false`), cuenta, resumen, auditoría, outbox | `CANCELLED` | `VERSION_CONFLICT`, `ORDER_UNIT_INVALID_STATE`, `ORDER_UNIT_PAID` |
| `VOID` | `PREPARING`, `READY` | Versión; motivo; permiso o token; sin pago | Igual que `CANCEL` | `VOIDED` | `VERSION_CONFLICT`, `ORDER_UNIT_INVALID_STATE`, `ORDER_UNIT_PAID`, `APPROVAL_INVALID` |

## Checks

### 2. Máquina de estados

| Evento | Estado previo | Validaciones | Documentos modificados | Estado resultante | Errores |
|---|---|---|---|---|---|
| Crear | Ninguno | Sesión `ACTIVE` | `checks`, sesión (`activity_epoch`), auditoría, outbox | `OPEN` | `SESSION_NOT_ACTIVE`, `IDEMPOTENCY_KEY_REUSED` |
| Asignar unidad nueva | `OPEN` | Desde Orders; D-17 | `check_allocations`, totales de `checks` | Sin cambio | `CHECK_INVALID_STATE` |
| Mover unidades | Origen `OPEN` o `REQUESTED`; destino `OPEN` | `If-Match` y versión del destino; misma sesión; sin pagos en curso ni confirmados | Asignaciones (cierra y crea), ambas cuentas, auditoría, outbox | Sin cambio | `VERSION_CONFLICT`, `CHECK_INVALID_STATE`, `CHECK_SESSION_MISMATCH`, `CHECK_HAS_CONFIRMED_PAYMENT`, `CHECK_HAS_OPEN_PAYMENT` |
| Solicitar | `OPEN` | `If-Match`; al menos una unidad válida | Cuenta, auditoría, outbox | `REQUESTED` | `VERSION_CONFLICT`, `CHECK_INVALID_STATE`, `CHECK_EMPTY` |
| Reabrir | `REQUESTED` | `If-Match`; permiso o token; sin pagos confirmados ni en curso | Cuenta, auditoría, outbox | `OPEN` | `VERSION_CONFLICT`, `CHECK_INVALID_STATE`, `CHECK_HAS_CONFIRMED_PAYMENT`, `CHECK_HAS_OPEN_PAYMENT`, `APPROVAL_INVALID` |
| Dar cortesía | `OPEN`, `REQUESTED` | `If-Match`; motivo; permiso o token; unidad válida sin cortesía; sin pagos confirmados ni en curso | `check_adjustments`, totales, auditoría, outbox | Sin cambio, o `PAID` si llega a 0 | `VERSION_CONFLICT`, `CHECK_INVALID_STATE`, `CHECK_HAS_CONFIRMED_PAYMENT`, `CHECK_HAS_OPEN_PAYMENT`, `ORDER_UNIT_INVALID_STATE`, `APPROVAL_INVALID` |
| Revertir cortesía | `OPEN`, `REQUESTED` | Igual que dar cortesía | Ajuste (`active = false`), totales, auditoría, outbox | Sin cambio | Igual que dar cortesía |
| Cancelar | `OPEN`, `REQUESTED` | `If-Match`; sin unidades válidas ni pagos | Cuenta, auditoría, outbox | `CANCELLED` | `VERSION_CONFLICT`, `CHECK_INVALID_STATE`, `CHECK_NOT_EMPTY` |
| Pago confirmado parcial | `REQUESTED`, `PARTIALLY_PAID` | Desde Payments | Totales | `PARTIALLY_PAID` | Ver Payments |
| Pago cubre el total | `REQUESTED`, `PARTIALLY_PAID` | Sin operaciones sin resolver | Totales | `PAID` | Ver Payments |

## Payments

### Pago

| Evento | Estado previo | Validaciones | Documentos modificados | Estado resultante | Errores |
|---|---|---|---|---|---|
| Crear en efectivo | Ninguno | Turno `OPEN`; cuenta `OPEN`, `REQUESTED` o `PARTIALLY_PAID`; sin `UNKNOWN`; monto dentro del saldo; entregado suficiente | `payments`, `checks`, `cash_shifts`, auditoría, outbox | `CONFIRMED` | `CASH_SHIFT_NOT_OPEN`, `CHECK_INVALID_STATE`, `PAYMENT_REQUIRES_RECONCILIATION`, `PAYMENT_EXCEEDS_BALANCE` |
| Crear con otro método | Ninguno | Igual, sin entregado | Igual | `PENDING` | Igual |
| Resultado confirmado | `PENDING` | Versión; referencia | Pago, cuenta, auditoría, outbox | `CONFIRMED` | `VERSION_CONFLICT`, `PAYMENT_INVALID_STATE` |
| Resultado rechazado | `PENDING` | Versión | Pago, cuenta (libera saldo), auditoría, outbox | `FAILED` | Igual |
| Cancelado antes de cobrar | `PENDING` | Versión | Igual | `CANCELLED` | Igual |
| Resultado incierto, o 15 min sin resultado | `PENDING` | Versión, o tarea programada | Pago, auditoría, outbox | `UNKNOWN` | Igual |
| Reconciliar | `UNKNOWN` | Versión; permiso; motivo; referencia si se confirma | Pago, cuenta, auditoría, outbox | `CONFIRMED` o `FAILED` | Igual |

### Reembolso

| Evento | Estado previo | Validaciones | Documentos modificados | Estado resultante | Errores |
|---|---|---|---|---|---|
| Crear en efectivo | Ninguno | Pago `CONFIRMED` sin otro reembolso activo; turno `OPEN`; motivo | `refunds`, `checks`, `cash_shifts`, auditoría, outbox | `CONFIRMED` | `PAYMENT_NOT_REFUNDABLE`, `REFUND_ALREADY_EXISTS`, `CASH_SHIFT_NOT_OPEN` |
| Crear con otro método | Ninguno | Igual | Igual | `PENDING` | Igual |
| Resultado | `PENDING` | Versión | Reembolso, cuenta, auditoría, outbox | `CONFIRMED`, `FAILED` o `UNKNOWN` | `VERSION_CONFLICT`, `REFUND_INVALID_STATE` |
| Reconciliar | `UNKNOWN` | Versión; permiso; motivo | Reembolso, cuenta, auditoría, outbox | `CONFIRMED` o `FAILED` | Igual |

## Inventory

### Conteo

| Evento | Estado previo | Validaciones | Documentos modificados | Estado resultante | Errores |
|---|---|---|---|---|---|
| Crear | Ninguno | Sin otro conteo abierto | `inventory_counts`, auditoría | `OPEN` | `INVENTORY_COUNT_ALREADY_OPEN`, `IDEMPOTENCY_KEY_REUSED` |
| Registrar líneas | `OPEN` | `If-Match`; ingredientes válidos | Conteo | `OPEN` | `VERSION_CONFLICT`, `INVENTORY_COUNT_INVALID_STATE` |
| Aplicar | `OPEN` | `If-Match`; `Idempotency-Key`; al menos una línea | Conteo, `inventory_movements`, auditoría, outbox | `APPLIED` | `VERSION_CONFLICT`, `INVENTORY_COUNT_INVALID_STATE` |
| Cancelar | `OPEN` | `If-Match`; motivo | Conteo, auditoría | `CANCELLED` | `VERSION_CONFLICT`, `INVENTORY_COUNT_INVALID_STATE` |

## Cash

### 2. Máquina de estados

| Evento | Estado previo | Validaciones | Documentos modificados | Estado resultante | Errores |
|---|---|---|---|---|---|
| Abrir | Ninguno | Sucursal activa; caja `ACTIVE`; sin turno activo; motivo si el fondo difiere del anterior | `cash_shifts`, `branches.operations_epoch`, auditoría, outbox | `OPEN` | `BRANCH_INACTIVE`, `CASH_REGISTER_INACTIVE`, `CASH_SHIFT_ALREADY_OPEN`, `CASH_SHIFT_REASON_REQUIRED`, `IDEMPOTENCY_KEY_REUSED`, `503 SERVICE_BUSY` |
| Registrar movimiento | `OPEN` | Monto mayor que 0; motivo | `cash_movements`, `activity_epoch` del turno, auditoría, outbox | `OPEN` | `CASH_SHIFT_NOT_OPEN`, `IDEMPOTENCY_KEY_REUSED` |
| Iniciar cierre | `OPEN` | `If-Match` | Turno, auditoría, outbox | `CLOSING` | `VERSION_CONFLICT`, `CASH_SHIFT_INVALID_STATE` |
| Cancelar cierre | `CLOSING` | `If-Match`; motivo | Turno, auditoría, outbox | `OPEN` | `VERSION_CONFLICT`, `CASH_SHIFT_INVALID_STATE` |
| Cerrar | `CLOSING` | `If-Match`; sin operaciones sin resolver; motivo si hay diferencia | Turno (con resumen congelado), auditoría, outbox | `CLOSED` | `VERSION_CONFLICT`, `CASH_SHIFT_INVALID_STATE`, `CASH_SHIFT_HAS_UNRESOLVED_OPERATIONS`, `CASH_SHIFT_REASON_REQUIRED` |

## Fiscal

### Factura

| Evento | Estado previo | Validaciones | Documentos modificados | Estado resultante | Errores |
|---|---|---|---|---|---|
| Crear | Ninguno | Emisor activo con CSD vigente; cuenta elegible; sin otra factura activa | `invoices`, `invoice_source_allocations`, `checks.active_invoice_id`, eventos, auditoría | `DRAFT` | `FISCAL_ISSUER_NOT_CONFIGURED`, `INVOICE_CHECK_NOT_ELIGIBLE`, `INVOICE_CHECK_ALREADY_INVOICED`, `INVOICE_CHECK_IN_STAMPED_GLOBAL`, `IDEMPOTENCY_KEY_REUSED` |
| Corregir | `DRAFT`, `STAMP_FAILED` | `If-Match` | Factura, eventos | `DRAFT` | `VERSION_CONFLICT`, `INVOICE_INVALID_STATE` |
| Descartar | `DRAFT`, `STAMP_FAILED` | `If-Match`; motivo | Factura, asignaciones (liberadas), cuenta, eventos, auditoría | `DISCARDED` | `VERSION_CONFLICT`, `INVOICE_INVALID_STATE` |
| Solicitar timbrado | `DRAFT`, `STAMP_FAILED` | `If-Match`; `Idempotency-Key` | Factura, eventos, outbox | `PENDING_STAMP` | `VERSION_CONFLICT`, `INVOICE_INVALID_STATE` |
| Tomar el trabajo | `PENDING_STAMP` | Proceso interno, con plazo de ocupación | Factura, eventos | `STAMPING` | No aplica |
| Respuesta del PAC | `STAMPING` | Proceso interno | Factura, archivos, eventos, outbox | `STAMPED`, `STAMP_FAILED` o `UNKNOWN` | No aplica |
| Reconciliar | `UNKNOWN` | Consulta al PAC por nuestra referencia | Factura, eventos, auditoría | `STAMPED`, `STAMP_FAILED`, `CANCELLED` o `UNKNOWN` | `FISCAL_STATUS_UNKNOWN`, `INVOICE_INVALID_STATE` |
| Solicitar cancelación | `STAMPED`, `CANCELLATION_REJECTED` | `If-Match`; motivo SAT; sustituta timbrada si el motivo es 01 | Factura, eventos, auditoría, outbox | `CANCELLATION_REQUESTED` | `VERSION_CONFLICT`, `INVOICE_INVALID_STATE`, `INVOICE_REPLACEMENT_REQUIRED` |
| El PAC acepta la solicitud | `CANCELLATION_REQUESTED` | Proceso interno | Factura, eventos | `CANCELLATION_IN_PROGRESS` o `UNKNOWN` | No aplica |
| Resultado del SAT | `CANCELLATION_IN_PROGRESS` | Proceso interno | Factura, asignaciones si se canceló, eventos, outbox | `CANCELLED` o `CANCELLATION_REJECTED` | No aplica |

## Printing

### 2. Máquina de estados

| Evento | Estado previo | Validaciones | Documentos modificados | Estado resultante | Errores |
|---|---|---|---|---|---|
| Crear (automático) | Ninguno | Desde outbox; clave derivada de origen, estación y tipo | `print_jobs` | `PENDING` | Sin ruta: `FAILED` con alerta |
| Crear (a petición) | Ninguno | Permiso del documento; ruta activa | `print_jobs`, auditoría | `PENDING` | `PRINT_ROUTE_NOT_CONFIGURED`, `IDEMPOTENCY_KEY_REUSED` |
| Reclamar | `PENDING` | Impresora del agente; `next_attempt_at` vencido | `print_jobs`, `print_job_attempts` | `CLAIMED` | Sin trabajos: lista vacía |
| Vence el plazo | `CLAIMED` | Proceso interno; no se envió nada | Trabajo, intento | `PENDING` | No aplica |
| Iniciar impresión | `CLAIMED` | Intento vigente | Trabajo, intento | `PRINTING` | `PRINT_JOB_LEASE_LOST` |
| Vence el plazo | `PRINTING` | Proceso interno | Trabajo, intento, outbox | `UNKNOWN` | No aplica |
| Resultado `PRINTED` | `PRINTING`, o `UNKNOWN` por vencimiento | Intento vigente | Trabajo, intento | `PRINTED` | `PRINT_JOB_LEASE_LOST` |
| Resultado `NOT_SENT` | `PRINTING`, o `UNKNOWN` por vencimiento | Intento vigente | Trabajo, intento, outbox si se agotan | `PENDING` con espera, o `FAILED` al tercer intento | `PRINT_JOB_LEASE_LOST` |
| Resultado `UNCERTAIN` | `PRINTING` | Intento vigente | Trabajo, intento, outbox | `UNKNOWN` | `PRINT_JOB_LEASE_LOST` |
| Resolver | `UNKNOWN` | `If-Match`; permiso | Trabajo, auditoría | `PRINTED` o `FAILED` | `VERSION_CONFLICT`, `PRINT_JOB_INVALID_STATE` |
| Reintentar | `FAILED` | `If-Match`; impresora opcional | Trabajo, auditoría | `PENDING` | `VERSION_CONFLICT`, `PRINT_JOB_INVALID_STATE` |
| Cancelar | `PENDING`, `FAILED`, `UNKNOWN` | `If-Match`; motivo | Trabajo, auditoría | `CANCELLED` | `VERSION_CONFLICT`, `PRINT_JOB_INVALID_STATE` |
| Reimprimir | `PRINTED`, `UNKNOWN` | Motivo; `Idempotency-Key` | Trabajo nuevo con `originalJobId`, auditoría | `PENDING` | `PRINT_JOB_INVALID_STATE` |
| Vence la comanda | `PENDING` | Proceso interno, solo comandas | Trabajo, outbox | `FAILED` (`PRINT_JOB_EXPIRED`) | No aplica |

## Otros recursos

- **Authentication y dispositivos:** Sesiones (`REVOKED`) y dispositivos (`RETIRED`) están en D-36_Ciclo_de_vida_de_credenciales.md.
- **Cuenta `REFUNDED`:** Se alcanza cuando se reembolsa todo lo pagado (P-8); sus unidades pueden moverse a otra cuenta si la factura ya tiene egreso total o está cancelada (F-8).
- **Catálogos:** Branch, Table, Product, Station, Modifier, Ingredient, CashRegister, Printer, PrintStation, PrintAgent y FiscalIssuer solo tienen `ACTIVE` e `INACTIVE` (Table además `MAINTENANCE`); cada cambio exige `If-Match` y motivo.
- **Complemento de pago:** Ver la sección 2 de Decisiones_Fiscal.md.
- **Lote de factura:** `ACTIVE` pasa a `SUPERSEDED` al excluir cuentas (F-7).
- **Usuario:** `INVITED` pasa a `ACTIVE` al definir la contraseña; `ACTIVE` e `INVITED` pasan a `INACTIVE`; `INACTIVE` pasa a `ACTIVE`.
