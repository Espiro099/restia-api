# Módulo Payments: decisiones del contrato

**Estado:** APROBADAS (P-1 a P-11)
**Fecha:** 2026-11-02
**Contrato:** `openapi/restia-api.yaml`

Se conserva lo definido en la sección 9 del documento maestro: los estados `PENDING`, `CONFIRMED`, `FAILED`, `CANCELLED` y `UNKNOWN`; `UNKNOWN` exige reconciliación antes de un nuevo intento; `PAID` requiere cubrir el total sin operaciones sin resolver; reembolsos completos en v1, sin restaurar inventario.

## 1. Decisiones aprobadas

| ID | Decisión | Recomendación | Alternativa |
|---|---|---|---|
| **P-1** | Terminal de pago | Contrato independiente del proveedor: el empleado registra el resultado; el webhook se agrega al elegirlo | Esperar a elegir proveedor |
| **P-2** | Flujo por método | Efectivo: `CONFIRMED` al crear. Tarjeta y transferencia: `PENDING` y luego resultado | Mismo flujo de dos pasos para todos |
| **P-3** | Pagos mixtos y parciales | Varios pagos por cuenta; confirmados, pendientes y desconocidos nunca superan el total | Un pago a la vez por cuenta |
| **P-4** | `UNKNOWN` | Bloquea nuevos intentos en esa cuenta; un `PENDING` de más de 15 min pasa a `UNKNOWN` | Bloquear también la caja completa |
| **P-5** | Reconciliación | Solo gerentes, con motivo y evidencia | También quien cobró |
| **P-6** | Propinas | **Amplía el documento:** campo `tip` en el pago, fuera del total de la cuenta | Sin propinas en el sistema |
| **P-7** | Reembolso | Por pago completo, con estados propios; el pago sigue `CONFIRMED` | Nuevo estado `REFUNDED` en el pago |
| **P-8** | Estado de cuenta tras reembolso | **Amplía el documento:** nuevo `REFUNDED` cuando se reembolsa todo lo pagado (**cambia Checks y Table Sessions**) | `PAID` con una marca |
| **P-9** | Salida del reembolso | Mismo método que el pago, en un turno `OPEN` actual | Permitir otro método |
| **P-10** | Datos de tarjeta | Nunca número completo ni CVV; solo marca, últimos 4 y autorización | Ninguno, ni últimos 4 |
| **P-11** | Permisos | Matriz de la sección 4 | Rol de cajero |

## 2. Máquinas de estados

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

Efectos en la cuenta:

- Un primer pago en una cuenta `OPEN` la pasa a `REQUESTED` (C-3).
- Confirmar con saldo pendiente la deja en `PARTIALLY_PAID`.
- Confirmar con saldo 0 la deja en `PAID`.

### Reembolso

| Evento | Estado previo | Validaciones | Documentos modificados | Estado resultante | Errores |
|---|---|---|---|---|---|
| Crear en efectivo | Ninguno | Pago `CONFIRMED` sin otro reembolso activo; turno `OPEN`; motivo | `refunds`, `checks`, `cash_shifts`, auditoría, outbox | `CONFIRMED` | `PAYMENT_NOT_REFUNDABLE`, `REFUND_ALREADY_EXISTS`, `CASH_SHIFT_NOT_OPEN` |
| Crear con otro método | Ninguno | Igual | Igual | `PENDING` | Igual |
| Resultado | `PENDING` | Versión | Reembolso, cuenta, auditoría, outbox | `CONFIRMED`, `FAILED` o `UNKNOWN` | `VERSION_CONFLICT`, `REFUND_INVALID_STATE` |
| Reconciliar | `UNKNOWN` | Versión; permiso; motivo | Reembolso, cuenta, auditoría, outbox | `CONFIRMED` o `FAILED` | Igual |

Un reembolso `FAILED` permite otro intento. Todas las operaciones son transaccionales y usan `Idempotency-Key`.

## 3. Reglas

**Saldo reservado (P-3)**

- La cuenta guarda `pending_total`, la suma de pagos en `PENDING` y `UNKNOWN`.
- Un pago nuevo se acepta solo si `amount ≤ total − paid_total − pending_total`.
- Por eso, cuando un pago confirmado deja el saldo en 0, no puede quedar otro pago en curso, y `PAID` cumple la regla del documento maestro.
- Dividir entre 3 personas son 3 pagos; pueden estar en terminales distintas al mismo tiempo.

**Efectivo**

- El cliente envía `amountTendered`, y el servidor calcula `change`.

**Propinas (P-6)**

- `tip` no entra en `check.total` ni en el saldo, y se reporta aparte en caja.
- Su reparto entre empleados queda fuera de v1.
- **Pendiente de validar:** su tratamiento fiscal, con contabilidad.

**Reembolso de una parte de los pagos**

Si una cuenta tuvo dos pagos y solo se reembolsa uno, conserva su estado y muestra `refundedTotal`. V1 no permite volver a cobrar ese monto en esa cuenta.

**Reembolso total y nuevo cobro (limitación de C-2)**

Una cuenta `REFUNDED` puede mover sus unidades a otra cuenta `OPEN` para cobrarlas de nuevo. Es la única salida de una cuenta con pagos, y se conserva el historial de asignaciones.

**Fiscal**

`refund.confirmed` se publica en outbox. Qué hacer con un CFDI ya timbrado se define en Fiscal (24.11).

**Proveedor de terminal (P-1)**

- Sin integración, una terminal independiente solo produce `UNKNOWN` si el empleado no pudo ver el resultado.
- Con integración, aparecerán tiempos de espera y notificaciones duplicadas. El webhook necesitará `ServiceAuth`, validación de firma e idempotencia por referencia.
- **Conciliación de lotes bancarios:** queda fuera de v1. Aquí "reconciliar" solo significa resolver un `UNKNOWN`.

## 4. Permisos

| Permiso | ADMIN | MANAGER | WAITER | KITCHEN |
|---|---|---|---|---|
| `payments.read` | Sí | Sí | Sí | No |
| `payments.create` | Sí | Sí | Sí | No |
| `payments.record_result` | Sí | Sí | Sí | No |
| `payments.reconcile` | Sí | Sí | No | No |
| `refunds.read` | Sí | Sí | No | No |
| `refunds.create` | Sí | Sí | No | No |
| `refunds.record_result` | Sí | Sí | No | No |
| `refunds.reconcile` | Sí | Sí | No | No |

El documento maestro no tiene rol de cajero. Con esta matriz, cualquier mesero puede cobrar sobre cualquier turno abierto de su sucursal. **Si solo ciertas personas pueden manejar efectivo, hace falta un rol nuevo o una regla contextual.**

## 5. Concurrencia

- **Pagos paralelos:** todos escriben en la cuenta. Chocan, y quien reintenta recalcula el saldo. Si ya no alcanza, recibe `409 PAYMENT_EXCEEDS_BALANCE`.
- **Sin `If-Match` al crear:** un pago ajeno no debe invalidar el de otra persona; lo que importa es el saldo.
- **Doble envío:** `Idempotency-Key` y el índice `unique(branch_id, idempotency_key)` devuelven el resultado original.
- **Pago contra cierre de caja:** ambos escriben en `cash_shifts`, de modo que el pago ve `CLOSING` o el cierre ve el pago. Es un requisito para Cash (24.10).
- **Doble reembolso:** el índice único parcial por pago activo deja pasar uno.

## 6. Cambios a contratos ya aprobados

| Módulo | Cambio | Origen |
|---|---|---|
| Checks | Nuevo estado `REFUNDED`; campos `refundedTotal`, `pendingTotal` y `tipTotal` | P-3, P-6, P-8 |
| Checks | Mover unidades también desde una cuenta `REFUNDED` | P-8 |
| Table Sessions | El cierre acepta cuentas `REFUNDED` | P-8 |

## 7. Requisitos para Cash (24.10)

- Rechazar el cierre si hay pagos o reembolsos en `PENDING` o `UNKNOWN`.
- Reportar efectivo, reembolsos y propinas por separado.
- Decidir el índice de turnos: `where status in OPEN,CLOSING` requiere una versión de MongoDB que admita `$in` en índices parciales. Un campo booleano lo evita, y es lo que se usa aquí en `refunds`.

## 8. Cambios al modelo físico

- **`payments`:** `uuid`, `branch_id`, `check_id`, `cash_shift_id`, `method`, `status`, `amount`, `tip`, `amount_tendered`, `change`, `card`, `reference`, `idempotency_key`, `created_by`, `confirmed_at`, `reconciled_by`, `reconcile_reason`, `version`.
- **`refunds`:** `uuid`, `branch_id`, `payment_id`, `check_id`, `cash_shift_id`, `status`, `active`, `amount`, `reference`, `reason`, `created_by`, `confirmed_at`, `reconciled_by`, `version`.
- **`checks`:** `pending_total`, `refunded_total`, `tip_total`.
- **Índices nuevos:** `unique(uuid)` en `payments` y `refunds`; `partial unique(branch_id, payment_id) where active=true` y `(branch_id, status, created_at)` en `refunds`.
- **Códigos de error nuevos:** `PAYMENT_EXCEEDS_BALANCE`, `PAYMENT_NOT_REFUNDABLE`, `REFUND_ALREADY_EXISTS`, `REFUND_INVALID_STATE`.
