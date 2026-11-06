# Módulo Checks: decisiones del contrato

**Estado:** APROBADAS (C-1 a C-10)
**Fecha:** 2026-11-02
**Contrato:** `openapi/restia-api.yaml`

Se conserva lo definido en D-16, D-17 y la sección 9 del documento maestro: la unidad es indivisible, mover unidades cierra una asignación y crea otra, `REQUESTED` no recibe unidades nuevas, y solo una cuenta sin pagos confirmados se puede reabrir, con autorización.

## 1. Decisiones aprobadas

| ID | Decisión | Recomendación | Alternativa |
|---|---|---|---|
| **C-1** | Dividir en partes iguales | Varios pagos sobre la misma cuenta; una unidad nunca se reparte entre cuentas | Unidades fraccionables, contra D-16 |
| **C-2** | Qué significa "unidad pagada" | Un pago confirmado congela **todas** las unidades de esa cuenta | Asignar cada pago a unidades concretas |
| **C-3** | Primer pago | Una cuenta `OPEN` pasa a `REQUESTED` al iniciar su primer pago; `OPEN` siempre significa sin pagos | Permitir pagos en `OPEN` |
| **C-4** | Varias cuentas `OPEN` | Las unidades van a la más reciente, salvo que la comanda indique `targetCheckId` (**cambia Orders**) | Solo una cuenta `OPEN` por sesión |
| **C-5** | Mover unidades | Solo dentro de la misma sesión; `If-Match` en origen y versión del destino en el cuerpo | Permitir entre sesiones |
| **C-6** | Totales | Se recalculan en la misma transacción, con unidades válidas y datos copiados; redondeo a 2 decimales por unidad | Calcularlos en cada lectura |
| **C-7** | Descuentos | **Amplía el documento:** solo cortesía de unidad completa, con motivo y autorización; sin porcentajes ni cupones en v1 | Sin cortesías, o descuentos completos |
| **C-8** | Cuentas en cero | Con unidades válidas y total 0 pasa a `PAID`; sin unidades válidas se cancela al cerrar la sesión (**precisa T-4**) | Cierre manual de cada una |
| **C-9** | Reabrir y dar cortesía | Con permiso directo, o con token de aprobación (D-37) | Solo gerentes con su propia sesión |
| **C-10** | Permisos | Matriz de la sección 4 | Ajustar por rol |

## 2. Máquina de estados

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

"Pagos en curso" son los pagos en `PENDING` o `UNKNOWN`. Todas las operaciones son transaccionales.

## 3. Reglas

**Totales**

- `subtotal` y `taxTotal` salen de los datos copiados en la partida. `total = subtotal + taxTotal − cortesías`, y `balance = total − paidTotal`.
- Solo cuentan las unidades activas que no estén en `CANCELLED` ni `VOIDED`. Una unidad en `PENDING` sí cuenta.
- Los precios incluyen impuestos (M-7), así que la base se obtiene dividiendo entre `1 + tasa`, por unidad, redondeando a 2 decimales.

**Cortesía**

- Lleva a cero una unidad completa, y resuelve la devolución de un platillo entregado (O-4).
- La unidad conserva su estado y el inventario no cambia.
- **Pendiente de validar:** cómo se refleja en el CFDI se debe revisar con contabilidad y el PAC en Fiscal (24.11).

**Dividir una cuenta**

1. Crear una cuenta vacía.
2. Mover unidades hacia ella.
3. Separar mesas no separa cuentas (sección 6 del documento maestro).

**Limitación de C-2**

Si un cliente paga una cuenta y luego una unidad no se puede preparar, esa unidad no se puede cancelar: responde `ORDER_UNIT_PAID`. V1 solo contempla reembolsos completos, así que habría que reembolsar todo y volver a cobrar. Es poco frecuente, porque se suele pagar al final, pero **si el restaurante cobra por adelantado, esto hay que resolverlo antes de implementar**.

## 4. Permisos

| Permiso | ADMIN | MANAGER | WAITER | KITCHEN |
|---|---|---|---|---|
| `checks.read` | Sí | Sí | Sí | No |
| `checks.create` | Sí | Sí | Sí | No |
| `checks.move_units` | Sí | Sí | Sí | No |
| `checks.request` | Sí | Sí | Sí | No |
| `checks.cancel` | Sí | Sí | Sí | No |
| `checks.reopen` | Sí | Sí | Con token | No |
| `checks.adjust` | Sí | Sí | Con token | No |

## 5. Concurrencia

- **Mover contra cobrar:** ambas operaciones escriben en la cuenta, así que MongoDB las hace chocar. Por eso un pago en curso bloquea los movimientos.
- **Mover la misma unidad dos veces:** el índice único parcial de `check_allocations` deja una sola asignación activa.
- **Crear una cuenta o comanda contra el cierre de la sesión:** escriben en `activity_epoch` (sección 5 de Orders). Cerrar una sesión cancela sus cuentas vacías en la misma transacción.
- **Elección de cuenta en comandas simultáneas:** también se ordenan por `activity_epoch`, así que no se crean dos cuentas automáticas.

## 6. Cambios a contratos ya aprobados

| Módulo | Cambio | Origen |
|---|---|---|
| Orders | `targetCheckId` opcional al crear una comanda, y `CHECK_INVALID_STATE` en sus conflictos | C-4 |
| Aprobaciones | Nueva acción `CHECK_ADJUSTMENT` | C-7 |
| Table Sessions | El cierre cancela cuentas vacías y acepta las que están en total 0 y `PAID` | C-8 |

## 7. Requisitos para Payments (24.8)

- El primer pago pasa la cuenta de `OPEN` a `REQUESTED` (C-3).
- **Propinas:** el documento no las menciona. Hay que definir si van en el pago, cómo se reportan en caja y que no alteren `total`.
- **Reembolso:** definir su efecto en los estados de pago y cuenta, que hoy no tienen uno que lo refleje.
- Respetar `UNKNOWN`: requiere reconciliación antes de un nuevo intento.

## 8. Cambios al modelo físico

- **Colección nueva `check_adjustments`:** `uuid`, `branch_id`, `order_item_unit_id`, `type` (`COURTESY`), `amount`, `reason`, `approved_by`, `active`, `created_by`, `created_at`. Índice `partial unique(branch_id, order_item_unit_id) where active=true`.
- **`checks`:** `uuid`, `session_id`, `number`, `label`, `status`, `subtotal`, `tax_total`, `adjustment_total`, `total`, `paid_total`, `balance`, `requested_at`, `business_date`, `version`.
- **`check_allocations`:** `branch_id`, `check_id`, `order_item_unit_id`, `active`, `created_by`, `created_at`, `closed_at`.
- **Índices nuevos en `checks`:** `unique(uuid)`, `unique(branch_id, session_id, number)`.
- **Códigos de error nuevos:** `CHECK_EMPTY`, `CHECK_NOT_EMPTY`, `CHECK_HAS_OPEN_PAYMENT`, `CHECK_SESSION_MISMATCH`.
