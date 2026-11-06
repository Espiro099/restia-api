# Módulo Orders y aprobaciones (D-37): decisiones del contrato

**Estado:** APROBADAS (D-37 y O-1 a O-11)
**Fecha:** 2026-11-02
**Contrato:** `openapi/restia-api.yaml`

Se conserva lo definido en D-07, D-11, D-16, D-17 y las secciones 7 y 8 del documento maestro: `orders → order_items → order_item_units`, los estados de la unidad, el consumo de inventario en `PREPARING` y la no restauración automática. También se aplican M-2 (una estación por unidad) y A-7 (borradores offline).

## 1. Decisiones aprobadas

| ID | Decisión | Recomendación | Alternativa |
|---|---|---|---|
| **D-37** | Autorización de un gerente en dispositivo ajeno | El gerente escribe su contraseña; se emite un token de un solo uso, ligado a la acción y las unidades, válido 2 min | PIN propio, o solicitud remota al dispositivo del gerente |
| **O-1** | Valores de `orders.status` | `OPEN`, `COMPLETED`, `CANCELLED`, derivados de las unidades | Más estados intermedios |
| **O-2** | Agregar y modificar platillos | Cada ronda es una comanda nueva; solo se editan notas mientras todo esté `PENDING`. Otros cambios: cancelar y volver a pedir | Editar comandas existentes |
| **O-3** | Anular después de preparar | **Amplía el documento:** también `READY → VOIDED` | Solo `PREPARING → VOIDED` |
| **O-4** | Unidad `DELIVERED` | No se revierte; una devolución se resuelve en Checks | Permitir anularla |
| **O-5** | Cambios de estado de unidades | Un endpoint por lote, hasta 50 unidades, todo o nada, con la versión de cada unidad en el cuerpo | Un endpoint por transición y unidad |
| **O-6** | Inventario al iniciar preparación | Se consume en la misma transacción; sin stock se rechaza, salvo override autorizado | Permitir stock negativo con alerta |
| **O-7** | Asignación a cuenta | Cada unidad nueva va a la cuenta `OPEN` de la sesión, o a una nueva si no hay (D-17) | Asignación manual |
| **O-8** | `CANCEL` | Motivo obligatorio | Motivo opcional |
| **O-9** | Quién atiende una mesa | Cualquier mesero de la sucursal, sin importar el responsable | Solo el responsable |
| **O-10** | Permisos | Matriz de la sección 4 | Ajustar por rol |
| **O-11** | Reasignar estación | Solo en `PENDING`, a una estación activa, con motivo | Sin reasignación |

## 2. D-37: aprobaciones

D-36 dejó abierto cómo se identifica un gerente en el teléfono de un mesero.

```text
1. El mesero intenta anular y no tiene permiso: 403 FORBIDDEN
2. El gerente escribe sus datos en ese dispositivo
     POST /branches/{branchId}/approvals
     { approverEmail, approverPassword, action, resourceIds, reason }
3. El servidor valida al gerente y devuelve un token de 2 min
4. La app repite la operación con X-Approval-Token
5. La transacción consume el token, que ya no sirve para otra operación
```

- **Por qué contraseña:** evita crear y administrar otra credencial en v1. Un PIN sería más rápido, y se puede agregar después de probar el flujo en piso.
- **Credenciales incorrectas:** responde `403 APPROVAL_DENIED`, nunca 401. Un 401 haría que la app del mesero renovara o cerrara su propia sesión.
- **Quién no lo necesita:** quien ya tiene `order_units.void` anula sin token.
- **Si la operación falla:** la transacción se revierte y el token sigue vigente hasta vencer.
- **Auditoría:** registra a quien solicita y a quien aprueba. Las contraseñas y tokens nunca se registran.
- **Límites de intentos:** 5 por minuto por aprobador e IP.
- **Acciones iniciales:** `ORDER_UNIT_VOID`, `INVENTORY_OVERRIDE`, `CHECK_REOPEN`. Las dos últimas se detallan en Inventory y Checks.

El requisito de D-11 de que el aprobador sea "idealmente distinto" se resolverá en Inventory (24.9).

## 3. Máquina de estados de la unidad

| Evento | Estado previo | Validaciones | Documentos modificados | Estado resultante | Errores |
|---|---|---|---|---|---|
| Crear comanda | Ninguno | Sesión `ACTIVE`; producto activo y disponible; modificadores válidos; estación activa | `orders`, `order_items`, `order_item_units`, `checks`, `check_allocations`, sesión, auditoría, outbox | `PENDING` | `SESSION_NOT_ACTIVE`, `PRODUCT_UNAVAILABLE`, `CLIENT_ORDER_CONFLICT`, `IDEMPOTENCY_KEY_REUSED` |
| `START` | `PENDING` | Versión; stock o token de override; sin bloqueo alimentario | Unidad (`recipe_version`), `inventory_movements`, `ingredients`, resumen de `orders`, auditoría, outbox | `PREPARING` | `VERSION_CONFLICT`, `ORDER_UNIT_INVALID_STATE`, `INVENTORY_INSUFFICIENT`, `INVENTORY_BLOCKED_FOOD_SAFETY`, `APPROVAL_INVALID` |
| `READY` | `PREPARING` | Versión | Unidad, resumen, auditoría, outbox | `READY` | `VERSION_CONFLICT`, `ORDER_UNIT_INVALID_STATE` |
| `DELIVER` | `READY` | Versión | Unidad, resumen, auditoría, outbox | `DELIVERED` | `VERSION_CONFLICT`, `ORDER_UNIT_INVALID_STATE` |
| `CANCEL` | `PENDING` | Versión; motivo; sin pago | Unidad, `check_allocations` (`active = false`), cuenta, resumen, auditoría, outbox | `CANCELLED` | `VERSION_CONFLICT`, `ORDER_UNIT_INVALID_STATE`, `ORDER_UNIT_PAID` |
| `VOID` | `PREPARING`, `READY` | Versión; motivo; permiso o token; sin pago | Igual que `CANCEL` | `VOIDED` | `VERSION_CONFLICT`, `ORDER_UNIT_INVALID_STATE`, `ORDER_UNIT_PAID`, `APPROVAL_INVALID` |

Todas las operaciones son transaccionales y usan `Idempotency-Key`.

Reglas adicionales:

- **Inventario tras `VOID`:** no se restaura. El desperdicio se registra aparte en Inventory.
- **`orders.status`:** se actualiza en la misma transacción. `OPEN` si queda alguna unidad activa; `COMPLETED` si todas terminaron y alguna fue entregada; `CANCELLED` si todas se cancelaron o anularon.
- **Estado de la unidad offline:** solo se capturan comandas. Las transiciones requieren conexión.
- **Impresión:** `order.created` se procesa de forma asíncrona (Printing, 24.12).

## 4. Permisos

| Permiso | ADMIN | MANAGER | WAITER | KITCHEN |
|---|---|---|---|---|
| `orders.read` | Sí | Sí | Sí | Sí |
| `orders.create` | Sí | Sí | Sí | No |
| `orders.update` | Sí | Sí | Sí | No |
| `order_units.prepare` (`START`, `READY`) | Sí | Sí | No | Sí |
| `order_units.deliver` | Sí | Sí | Sí | No |
| `order_units.cancel` | Sí | Sí | Sí | Sí |
| `order_units.void` | Sí | Sí | Con token | Con token |
| `order_units.reassign` | Sí | Sí | No | Sí |

Como BAR es una estación (D-12), quien prepara bebidas necesita el rol KITCHEN o permisos equivalentes.

## 5. Concurrencia

- **Comanda contra cierre de sesión:** crear una comanda incrementa `table_sessions.activity_epoch` en su transacción, y el cierre también escribe en ese documento. Chocan, y quien reintenta ve el resultado. No cambia `version`, para no invalidar el `If-Match` de otras operaciones de la sesión.
- **Doble envío de comanda:** lo cubren `Idempotency-Key` y `unique(branch_id, client_order_uuid)`. El mismo `clientOrderUuid` con datos distintos responde `409 CLIENT_ORDER_CONFLICT`.
- **Dos personas sobre una unidad:** gana la primera; la otra recibe `VERSION_CONFLICT`. En un lote, un solo conflicto revierte todo y `details` indica qué unidades fallaron.

## 6. Riesgo principal: contención en inventario

`START` actualiza `ingredients.stock_actual` dentro de la transacción. En hora pico, muchas unidades que comparten un ingrediente, como un aceite o una salsa, chocarían en el mismo documento y generarían reintentos o `503 SERVICE_BUSY` justo cuando la cocina tiene más trabajo.

Es lo primero que se debe medir en las pruebas de carga (tarea 37). Si resulta un problema, la alternativa es validar contra `inventory_movements` y actualizar la proyección de forma asíncrona, aceptando un pequeño margen de sobreconsumo. Esa decisión se tomaría en Inventory.

## 7. Requisitos para Checks (24.7)

- Definir cómo se calculan los totales al agregar, cancelar o anular unidades.
- Definir la devolución de un platillo ya entregado (O-4), por ejemplo una cortesía o un ajuste. Hoy el documento no contempla descuentos.
- Respetar D-17: una cuenta `REQUESTED` no recibe unidades nuevas.
- Escribir en la sesión para no competir con su cierre (T-4).

## 8. Cambios al modelo físico

- **Colección nueva:** `approval_grants`: `token_hash`, `branch_id`, `action`, `resource_ids`, `requested_by`, `approved_by`, `reason`, `expires_at` (TTL), `used_at`. Índice `unique(token_hash)`.
- **`orders`:** `uuid`, `session_id`, `client_order_uuid`, `status`, `total`, `business_date`, `captured_at`, `created_by`, `version`.
- **`order_items`:** `uuid`, `order_id`, `product_id`, `name_snapshot`, `quantity`, `unit_price`, `modifiers_snapshot`, `fiscal_snapshot`, `notes`, `version`.
- **`order_item_units`:** `uuid`, `order_id`, `order_item_id`, `unit_number`, `status`, `station_id`, `recipe_version`, `reason`, marcas de tiempo por estado, `version`.
- **`table_sessions`:** `activity_epoch`.
- **Índices nuevos:** `unique(uuid)` en las tres colecciones de comandas.
- **Códigos de error nuevos:** `PRODUCT_UNAVAILABLE`, `CLIENT_ORDER_CONFLICT`, `ORDER_UNIT_PAID`, `INVENTORY_BLOCKED_FOOD_SAFETY`, `APPROVAL_DENIED`, `APPROVAL_INVALID`.
