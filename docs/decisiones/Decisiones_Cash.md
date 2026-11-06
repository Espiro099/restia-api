# Módulo Cash: decisiones del contrato

**Estado:** APROBADAS (CA-1 a CA-13)
**Fecha:** 2026-11-02
**Contrato:** `openapi/restia-api.yaml`

Se conserva lo definido en D-10 y la sección 12 del documento maestro: los estados `OPEN`, `CLOSING` y `CLOSED`, como máximo un turno `OPEN` o `CLOSING` por caja, varias cajas por sucursal, y ningún cierre con pagos `UNKNOWN` o inconsistencias financieras sin resolver.

El documento maestro no define cómo se calcula el efectivo esperado, qué pasa con una diferencia ni si existen retiros. Esas son las decisiones de fondo de este módulo.

## 1. Decisiones aprobadas

| ID | Decisión | Recomendación | Alternativa |
|---|---|---|---|
| **CA-1** | Cajas | Catálogo por sucursal; `ACTIVE` e `INACTIVE`; no se desactiva con un turno activo | Caja implícita por sucursal, contra D-10 |
| **CA-2** | Dueño del turno | El turno es de la caja, no de una persona; quien abre y quien cierra pueden ser distintos | Un turno por empleado |
| **CA-3** | Cierre | En dos pasos: `CLOSING` frena operaciones nuevas, y `CLOSED` registra el conteo. Se puede volver a `OPEN` | Un solo paso, sin usar `CLOSING` |
| **CA-4** | Qué bloquea el cierre | Pagos y reembolsos del turno en `PENDING` o `UNKNOWN`. Las cuentas abiertas no lo bloquean | Exigir también cuentas cerradas |
| **CA-5** | Movimientos manuales | **Amplía el documento:** `cash_movements` con `DEPOSIT` y `WITHDRAWAL`; inmutables, con motivo | Sin movimientos manuales |
| **CA-6** | Efectivo esperado | Fórmula de la sección 3, calculada desde pagos, reembolsos y movimientos; se congela al cerrar | Guardar acumulados en el turno |
| **CA-7** | Propina en un reembolso | El reembolso devuelve también la propina, en un campo aparte (**cambia Payments**) | Devolver solo el monto de la cuenta |
| **CA-8** | Diferencias al contar | **Interpreta "inconsistencias":** toda diferencia exige motivo; si supera la tolerancia de la caja, además queda marcada y genera una alerta. No impide cerrar | Exigir un aprobador distinto |
| **CA-9** | Apertura | Se declara el fondo inicial; si difiere de lo que dejó el turno anterior, exige motivo | Fondo fijo, o sin comparación |
| **CA-10** | Turno cerrado | Inmutable; un error se corrige con un movimiento en el turno actual, con motivo | Reabrir con autorización |
| **CA-11** | Turno olvidado | Alerta a las 24 horas; no se cierra solo ni bloquea cobros | Bloquear cobros tras cierto tiempo |
| **CA-12** | Índice de turno único | **Precisa el índice del documento:** campo booleano `active`, en lugar de `status in OPEN,CLOSING` | Mantener `$in` en el filtro parcial |
| **CA-13** | Permisos | Matriz de la sección 5: solo gerentes operan la caja; WAITER solo ve los turnos abiertos para cobrar | Rol de cajero |

## 2. Máquina de estados

| Evento | Estado previo | Validaciones | Documentos modificados | Estado resultante | Errores |
|---|---|---|---|---|---|
| Abrir | Ninguno | Sucursal activa; caja `ACTIVE`; sin turno activo; motivo si el fondo difiere del anterior | `cash_shifts`, `branches.operations_epoch`, auditoría, outbox | `OPEN` | `BRANCH_INACTIVE`, `CASH_REGISTER_INACTIVE`, `CASH_SHIFT_ALREADY_OPEN`, `CASH_SHIFT_REASON_REQUIRED`, `IDEMPOTENCY_KEY_REUSED`, `503 SERVICE_BUSY` |
| Registrar movimiento | `OPEN` | Monto mayor que 0; motivo | `cash_movements`, `activity_epoch` del turno, auditoría, outbox | `OPEN` | `CASH_SHIFT_NOT_OPEN`, `IDEMPOTENCY_KEY_REUSED` |
| Iniciar cierre | `OPEN` | `If-Match` | Turno, auditoría, outbox | `CLOSING` | `VERSION_CONFLICT`, `CASH_SHIFT_INVALID_STATE` |
| Cancelar cierre | `CLOSING` | `If-Match`; motivo | Turno, auditoría, outbox | `OPEN` | `VERSION_CONFLICT`, `CASH_SHIFT_INVALID_STATE` |
| Cerrar | `CLOSING` | `If-Match`; sin operaciones sin resolver; motivo si hay diferencia | Turno (con resumen congelado), auditoría, outbox | `CLOSED` | `VERSION_CONFLICT`, `CASH_SHIFT_INVALID_STATE`, `CASH_SHIFT_HAS_UNRESOLVED_OPERATIONS`, `CASH_SHIFT_REASON_REQUIRED` |

Todas las operaciones son transaccionales. En `CLOSING` no se aceptan pagos, reembolsos ni movimientos nuevos, pero sí el resultado y la reconciliación de los que ya existían. Sin esto, un turno nunca podría resolverlos.

## 3. Reglas

**Efectivo esperado (CA-6)**

```text
esperado = fondo inicial
         + efectivo cobrado (amount + tip de pagos CASH en CONFIRMED)
         + depósitos
         − retiros
         − efectivo devuelto (amount + tip de reembolsos CASH en CONFIRMED)
```

- El cambio entregado no aparece porque `amount + tip` ya es lo que queda en el cajón.
- Un reembolso cuenta en el turno donde se entregó el dinero, aunque el pago original sea de otro turno (P-9).
- Tarjeta y transferencia no afectan el efectivo esperado; se reportan por método.
- Las propinas se reportan aparte (P-6). Su reparto sigue fuera de v1: si se pagan desde el cajón, se registran como `WITHDRAWAL`.

**Diferencias (CA-8)**

- `diferencia = contado − esperado`, con signo.
- Si no es 0, `differenceReason` es obligatorio.
- Si su valor absoluto supera `differenceTolerance` de la caja, el turno queda con `reviewRequired = true` y se publica `cash_shift.difference_detected`.
- La tolerancia predeterminada es 0, así que toda diferencia se revisa hasta que el restaurante defina otro valor.
- Permitir el cierre es deliberado: un cajón con faltante es un hecho, y bloquearlo impediría abrir el siguiente turno. Lo que no se permite es cerrarlo sin explicación.

**Apertura (CA-9)**

- Al cerrar se declara `cashLeftInDrawer`, lo que queda para el siguiente turno.
- Al abrir, si `openingAmount` difiere de ese valor, hace falta `openingDifferenceReason`. Así se detecta dinero que desapareció entre turnos.
- En el primer turno de una caja no hay comparación.

**Resumen**

- Con el turno activo, el resumen se calcula en cada lectura. Al cerrar se guarda y deja de cambiar.
- Incluye fondo, efectivo, propinas, reembolsos, movimientos, totales por método, esperado, contado, diferencia y operaciones sin resolver.

**Cuentas abiertas (CA-4)**

Una cuenta puede seguir abierta al cambiar de turno, porque lo que pertenece al turno son los pagos, no las cuentas. Una mesa que cubre dos turnos paga en el que esté abierto.

**Propina en un reembolso (CA-7)**

P-7 dice "pago completo" sin aclarar la propina. Al revertir un cargo de tarjeta se devuelve el total, así que se recomienda lo mismo en todos los métodos. `refundedTotal` sigue sumando solo montos de cuenta, para que `REFUNDED` conserve su significado. **Si la propina ya se repartió, el restaurante absorbe ese monto.**

**Fuera de v1**

- Conteo por denominaciones.
- Depósitos bancarios y bóveda.
- Conteo a ciegas: solo aporta control si quien cuenta no ve los totales, y aquí cierran gerentes, que sí los ven. Conviene retomarlo si se agrega un rol de cajero.

## 4. Concurrencia

- **Pago contra cierre (requisito de Payments, sección 5):** crear un pago, reembolso o movimiento incrementa `cash_shifts.activity_epoch`; iniciar el cierre escribe en el mismo documento. MongoDB hace chocar ambas operaciones, y quien reintenta ve el estado nuevo: el pago recibe `CASH_SHIFT_NOT_OPEN`, o el cierre incluye el pago.
- **`activity_epoch` no cambia `version`:** si cada cobro cambiara la versión, iniciar el cierre en plena operación fallaría una y otra vez con `VERSION_CONFLICT`.
- **Resolver un pago contra cerrar:** no hace falta otra escritura. Quien resuelve solo reduce lo pendiente, y el cierre rechaza si en su lectura aún ve algo sin resolver.
- **Dos aperturas en una caja:** el índice único parcial deja pasar una, y la otra recibe `CASH_SHIFT_ALREADY_OPEN`.
- **Abrir contra desactivar:** ambas escriben en `operations_epoch`, igual que el resto de operaciones de sucursal. Esto cubre desactivar la sucursal y desactivar la caja.
- **Contención:** los cobros de una misma caja se serializan por `activity_epoch`. Una caja procesa pocos cobros por minuto y las transacciones son cortas, así que no debería notarse. Falta medirlo con carga (tarea 37).

## 5. Permisos

| Permiso | ADMIN | MANAGER | WAITER | KITCHEN |
|---|---|---|---|---|
| `cash.read` (montos, resumen, movimientos) | Sí | Sí | No | No |
| `cash.read_open` (turnos abiertos, sin montos) | Sí | Sí | Sí | No |
| `cash.manage_registers` | Sí | Sí | No | No |
| `cash.open` | Sí | Sí | No | No |
| `cash.move` | Sí | Sí | No | No |
| `cash.close` | Sí | Sí | No | No |

**Revisa esta matriz con la operación real.** Exige un gerente al abrir y cerrar cada turno. A la vez, por P-11, cualquier mesero puede cobrar en efectivo en cualquier turno abierto, de modo que una diferencia no se puede atribuir a una persona. Si un encargado de caja responde por el dinero, hace falta un rol de cajero, y eso también cambia los permisos de Payments.

## 6. Cambios a contratos ya aprobados

| Módulo | Cambio | Origen |
|---|---|---|
| Payments | `Refund` agrega `tip`; el reembolso devuelve `amount + tip` | CA-7 |
| Payments | Crear pagos y reembolsos escribe en `activity_epoch`, no en `version` del turno | Sección 4 |
| Payments | Índice nuevo `(branch_id, cash_shift_id, status)` en `refunds` | CA-6 |

## 7. Requisitos para otros módulos

- **Reports:** usar el resumen congelado de los turnos cerrados, en lugar de recalcularlo.
- **Fiscal (24.11):** un turno cerrado no se ve afectado por la factura. Lo que debe definir es el efecto fiscal de reembolsos y propinas.

## 8. Cambios al modelo físico

- **`cash_registers`:** `uuid`, `branch_id`, `code`, `name`, `status`, `difference_tolerance`, `version`.
- **`cash_shifts`:** `uuid`, `branch_id`, `cash_register_id`, `status`, `active`, `business_date`, `opening_amount`, `opening_difference`, `opening_difference_reason`, `opened_by`, `opened_at`, `closing_started_at`, `closed_by`, `closed_at`, `expected_cash`, `counted_cash`, `difference`, `difference_reason`, `cash_left_in_drawer`, `review_required`, `closing_summary`, `activity_epoch`, `idempotency_key`, `version`.
- **Colección nueva `cash_movements`:** `uuid`, `branch_id`, `cash_shift_id`, `type`, `amount`, `reason`, `idempotency_key`, `created_by`, `created_at`.
- **`refunds`:** `tip`.
- **Índices nuevos:** `unique(uuid)` en las tres; `unique(branch_id, code)` en `cash_registers`; `unique(branch_id, idempotency_key)` en `cash_shifts` y `cash_movements`; `(branch_id, cash_shift_id, created_at)` en `cash_movements`; `(branch_id, cash_shift_id, status)` en `refunds`.
- **Índice que cambia:** `partial unique(branch_id, cash_register_id) where active=true`, en lugar del filtro por `status`. No depende de la versión de MongoDB y sigue el patrón de `refunds`.
- **Códigos de error nuevos:** `CASH_SHIFT_INVALID_STATE`, `CASH_SHIFT_HAS_UNRESOLVED_OPERATIONS`, `CASH_SHIFT_REASON_REQUIRED`, `CASH_REGISTER_INACTIVE`, `CASH_REGISTER_HAS_ACTIVE_SHIFT`.
