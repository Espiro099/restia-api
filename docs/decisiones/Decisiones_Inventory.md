# Módulo Inventory: decisiones del contrato

**Estado:** APROBADAS (I-1 a I-11)
**Fecha:** 2026-11-02
**Contrato:** `openapi/restia-api.yaml`

Se conserva lo definido en D-11 y la sección 8 del documento maestro: `inventory_movements` es la fuente de verdad, `stock_actual` es una proyección, todo ajuste genera movimiento y auditoría, el consumo es idempotente por unidad, ingrediente y versión de receta, una anulación no restaura inventario, y los overrides nunca saltan un bloqueo de seguridad alimentaria.

## 1. Decisiones aprobadas

| ID | Decisión | Recomendación | Alternativa |
|---|---|---|---|
| **I-1** | Contención en hora pico (O-6) | **Precisa O-6:** el movimiento se crea en la transacción, y `stock_actual` se actualiza segundos después | Actualizar el stock dentro de la transacción |
| **I-2** | Idempotencia del consumo | Clave determinista por unidad, ingrediente y versión de receta, sobre el índice existente | Índice único nuevo de tres campos |
| **I-3** | Aprobador de un override (D-11) | Distinto del solicitante; un gerente puede aprobarse a sí mismo, y queda marcado para revisión | Siempre distinto |
| **I-4** | Bloqueo alimentario | Por ingrediente, sin lotes ni caducidades en v1 | Control por lote |
| **I-5** | Tipos de movimiento | `CONSUMPTION`, `RECEIPT`, `ADJUSTMENT`, `WASTE`, `COUNT_ADJUSTMENT`; son inmutables | Otros tipos |
| **I-6** | Recepciones | Inmutables, con proveedor en texto libre; un error se corrige con un ajuste | Colección de proveedores y reversión |
| **I-7** | Conteos físicos | `OPEN`, `APPLIED`, `CANCELLED`; un solo conteo abierto por sucursal | Varios conteos por área |
| **I-8** | Ingredientes | Por sucursal (M-1); unidad base fija; `INACTIVE` si ninguna receta vigente los usa | Catálogo global |
| **I-9** | Costos | Se guarda el costo en cada recepción, sin valuación de inventario en v1 | Costo promedio |
| **I-10** | Agotados | Stock bajo solo publica una alerta; marcar `SOLD_OUT` sigue siendo manual (M-6) | Agotado automático |
| **I-11** | Permisos | Matriz de la sección 5 | Ajustar por rol |

## 2. I-1: contención de inventario

Este es el riesgo que marqué en Orders. Si cada `START` escribe en `ingredients.stock_actual`, muchas unidades que comparten un ingrediente chocan en el mismo documento justo en hora pico.

| | A. Proyección asíncrona (recomendada) | B. Stock en la transacción |
|---|---|---|
| Contención en cocina | Ninguna por ingrediente | Alta en ingredientes comunes |
| Exactitud de la validación | Puede fallar por unos segundos | Exacta |
| Efecto de un error | Un consumo ligeramente mayor que el stock | Reintentos o `503` durante el servicio |

Se recomienda A. El inventario de un restaurante ya es aproximado hasta el siguiente conteo, y un pequeño sobreconsumo cuesta menos que frenar la cocina. Encaja con el documento maestro, porque `stock_actual` ya es una proyección reconstruible.

Funcionamiento:

1. `START` lee `stock_actual`, sin escribir en él, y si no alcanza responde `409 INVENTORY_INSUFFICIENT`.
2. En la misma transacción crea los movimientos, con `projected_at = null`.
3. Un proceso aplica los movimientos pendientes con `$inc` y los marca como proyectados, también en una transacción, de modo que nada se aplica dos veces.
4. Un comando puede reconstruir `stock_actual` desde los movimientos.

La API devuelve `stockAsOf` para indicar hasta cuándo está actualizado el valor. Falta medir el retraso real con carga (tarea 37). Si el proceso se detiene, `stock_actual` queda desactualizado y las validaciones pierden exactitud, por lo que se necesita una alerta.

## 3. Reglas

**Consumo al pasar a `PREPARING`**

- Se suman receta y ajustes de modificadores por ingrediente, y se crea un movimiento por cada uno.
- La `idempotency_key` se deriva de unidad, ingrediente y versión de receta, y usa el índice `unique(branch_id, idempotency_key)`, por lo que no hace falta otro.
- Una unidad de un producto sin receta no genera movimientos.

**Override (D-11)**

- Sin stock, `START` responde `INVENTORY_INSUFFICIENT` con los ingredientes faltantes.
- Se repite con `X-Approval-Token` de la acción `INVENTORY_OVERRIDE`. El stock puede quedar negativo.
- El aprobador necesita `inventory.override`. Si coincide con quien solicita, el movimiento queda con `self_approved = true` para revisión.
- Un bloqueo alimentario responde `INVENTORY_BLOCKED_FOOD_SAFETY`, y ningún token lo salta.

**Bloqueo alimentario**

- Bloquear no usa `If-Match`, porque una medida de seguridad no debe perder ante una edición simultánea.
- Como `START` no escribe en el ingrediente, una unidad iniciada en el mismo instante podría pasar. Ese caso queda visible en auditoría; el bloqueo publica `ingredient.food_safety_blocked` para revisar lo que esté en preparación.
- No marca los productos como agotados de forma automática.
- **Fuera de alcance:** el documento maestro no cubre alérgenos. Si el menú los debe informar, hay que agregarlos.

**Conteo físico**

- Cada línea guarda lo contado y la hora, y se calcula lo esperado a esa hora **sumando los movimientos hasta ese momento**, no con `stock_actual`.
- Al aplicar, se crea un `COUNT_ADJUSTMENT` con la diferencia. Los consumos hechos mientras tanto se conservan.
- Un solo conteo abierto evita aplicar dos veces la misma diferencia.

**Correcciones**

- Un movimiento nunca se edita. Se corrige con otro, de tipo `ADJUSTMENT` y con motivo.
- El desperdicio de una unidad anulada se registra como `WASTE`, con `orderUnitId` opcional.

## 4. Máquinas de estados

### Conteo

| Evento | Estado previo | Validaciones | Documentos modificados | Estado resultante | Errores |
|---|---|---|---|---|---|
| Crear | Ninguno | Sin otro conteo abierto | `inventory_counts`, auditoría | `OPEN` | `INVENTORY_COUNT_ALREADY_OPEN`, `IDEMPOTENCY_KEY_REUSED` |
| Registrar líneas | `OPEN` | `If-Match`; ingredientes válidos | Conteo | `OPEN` | `VERSION_CONFLICT`, `INVENTORY_COUNT_INVALID_STATE` |
| Aplicar | `OPEN` | `If-Match`; `Idempotency-Key`; al menos una línea | Conteo, `inventory_movements`, auditoría, outbox | `APPLIED` | `VERSION_CONFLICT`, `INVENTORY_COUNT_INVALID_STATE` |
| Cancelar | `OPEN` | `If-Match`; motivo | Conteo, auditoría | `CANCELLED` | `VERSION_CONFLICT`, `INVENTORY_COUNT_INVALID_STATE` |

### Otros recursos

- **Movimientos y recepciones:** inmutables, sin estados.
- **Ingrediente:** `ACTIVE` e `INACTIVE`, y bloqueo alimentario aparte. Desactivar con una receta vigente responde `INGREDIENT_IN_USE`.

## 5. Permisos

| Permiso | ADMIN | MANAGER | WAITER | KITCHEN |
|---|---|---|---|---|
| `inventory.read` | Sí | Sí | No | Sí |
| `inventory.manage` (catálogo) | Sí | Sí | No | No |
| `inventory.adjust` | Sí | Sí | No | No |
| `inventory.record_waste` | Sí | Sí | No | Sí |
| `inventory.receive` | Sí | Sí | No | Sí |
| `inventory.count` | Sí | Sí | No | Sí |
| `inventory.apply_count` | Sí | Sí | No | No |
| `inventory.override` | Sí | Sí | No | No |
| `inventory.food_safety` | Sí | Sí | No | Bloquear, no desbloquear |

KITCHEN puede bloquear un ingrediente porque suele detectar el problema primero. Solo un gerente lo desbloquea.

## 6. Cambios a contratos ya aprobados

| Módulo | Cambio | Origen |
|---|---|---|
| Orders | `START` crea movimientos, pero el stock se actualiza después | I-1 |
| Orders | Se elimina `INVENTORY_OVERRIDE_REQUIRED`; `INVENTORY_INSUFFICIENT` cubre el caso, con los faltantes en `details` | Sección 3 |

## 7. Cambios al modelo físico

- **`ingredients`:** `uuid`, `branch_id`, `code`, `name`, `base_unit`, `min_stock`, `stock_actual`, `stock_as_of`, `status`, `food_safety_block`, `version`.
- **`inventory_movements`:** `uuid`, `branch_id`, `ingredient_id`, `type`, `quantity`, `source_type`, `source_id`, `recipe_version`, `reason`, `override`, `approved_by`, `self_approved`, `idempotency_key`, `projected_at`, `created_by`, `created_at`.
- **`inventory_receipts`:** `uuid`, `branch_id`, `supplier_name`, `reference`, `lines[]` (ingrediente, cantidad, costo unitario), `received_at`, `created_by`.
- **`inventory_counts`:** `uuid`, `branch_id`, `status`, `lines[]` (ingrediente, contado, esperado, hora), `version`.
- **Índices nuevos:** `unique(uuid)` en las cuatro; `unique(branch_id, code)` en `ingredients`; `(projected_at)` parcial para pendientes en `inventory_movements`; `partial unique(branch_id) where status=OPEN` en `inventory_counts`.
- **Códigos de error nuevos:** `INGREDIENT_IN_USE`, `INVENTORY_COUNT_ALREADY_OPEN`, `INVENTORY_COUNT_INVALID_STATE`.
