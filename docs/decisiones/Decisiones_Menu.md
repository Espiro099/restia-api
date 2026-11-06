# Módulo Menu: decisiones del contrato

**Estado:** APROBADAS (M-1 a M-11; M-2 con la opción A)
**Fecha:** 2026-11-02
**Contrato:** `openapi/restia-api.yaml`

Se conserva D-13: recetas versionadas e históricamente inmutables, y rutas en `product_preparation_routes`. El documento maestro solo nombra las colecciones `products`, `modifiers`, `recipes`, `stations` y `product_preparation_routes`, sin campos ni índices, así que lo demás es propuesta.

## 1. Decisiones aprobadas

| ID | Decisión | Recomendación | Alternativa |
|---|---|---|---|
| **M-1** | Alcance del catálogo | Por sucursal | Catálogo global con ajustes por sucursal |
| **M-2** | Estaciones de una unidad | **Una por unidad**; las rutas son alternativas por prioridad (sección 2) | Tareas de producción por estación |
| **M-3** | Modificadores | Cada documento es un grupo con opciones internas, mínimo y máximo | Colecciones separadas para grupos y opciones |
| **M-4** | Recetas | Publicar crea una versión activa, sin borradores; receta opcional | Flujo de borrador y publicación |
| **M-5** | Versión de receta aplicada | La vigente al pasar a `PREPARING`, guardada en la unidad | La vigente al crear la comanda |
| **M-6** | Producto agotado | `availability` rápida, sin `If-Match`; también para KITCHEN y WAITER | Solo gerentes, con `If-Match` |
| **M-7** | Datos fiscales | En el producto: clave SAT, unidad SAT, objeto de impuesto y tasa. Precio con impuestos incluidos | Precio sin impuestos |
| **M-8** | Duplicados al crear | `code` único por sucursal, en lugar de `Idempotency-Key` | `Idempotency-Key` |
| **M-9** | Menú para clientes | `GET /menu` completo, con `ETag` y `304` (agrega 304 a la sección 30) | Solo los listados por recurso |
| **M-10** | Categorías | Campo de texto `category` y `displayOrder` | Colección `categories` |
| **M-11** | Permisos | Matriz de la sección 4 | Ajustar por rol |

## 2. M-2: contradicción resuelta con la opción A

D-13 dice que un producto puede usar **varias estaciones**, pero el modelo físico da a cada unidad **un solo** `station_id` y un solo estado, según el índice `(branch_id, status, station_id, created_at)`. Una misma unidad no puede estar lista en cocina y pendiente en barra.

| | A. Una estación por unidad (recomendada) | B. Tareas por estación |
|---|---|---|
| Significado de "varias" | Alternativas: Parrilla 1 o Parrilla 2 | Trabajo simultáneo: cocina y barra para una misma unidad |
| Modelo físico | Sin cambios | Nueva colección de tareas; la unidad queda lista cuando terminen todas |
| Impacto | Bajo | Afecta a Orders, impresión, pantallas de cocina e inventario |
| Producto combinado | Se vende como productos separados | Un solo producto |

Se recomienda A porque respeta el modelo y la máquina de estados aprobados. La limitación es que un combo de hamburguesa y malteada no sería un solo producto con dos estaciones. **Si el menú real necesita combos, conviene elegir B ahora**, porque cambiarlo después obligaría a rehacer Orders.

Con A, cada unidad toma al crearse la primera estación activa por prioridad. Un producto necesita al menos una ruta para activarse.

## 3. Reglas

**Recetas**

- Cada publicación crea la siguiente versión y marca la anterior como `SUPERSEDED`. Ninguna versión se modifica.
- Las cantidades usan la unidad base del ingrediente; no hay conversión de unidades en v1.
- Un producto sin receta no consume inventario. Una vez publicada, v1 no permite volver a dejarlo sin receta.
- Los ingredientes se validan contra `ingredients`, que se especifica en Inventory (tarea 24.9).

**Modificadores**

- Las opciones no se borran; se desactivan. Así se conservan sus identificadores.
- Una opción puede ajustar ingredientes y sumar precio. La suma hereda el tratamiento fiscal del producto.

**Cambios de precio y catálogo**

- Editar un producto no altera comandas existentes, porque Orders guarda una copia de los datos.
- No hay `DELETE`; se desactiva.

**Estaciones**

- Una estación no se puede desactivar si tiene unidades sin terminar o si es la única ruta de un producto activo (`409 STATION_IN_USE`).

**Menú compuesto**

- `GET /menu` incluye productos activos y modificadores, sin recetas ni costos. Android lo guarda en caché para el modo offline (A-7).
- Cada cambio de catálogo incrementa `branches.menu_version`, que se usa como `ETag`. No usa `operations_epoch`.

**Datos fiscales: pendiente de validar**

Los campos propuestos y que el precio incluya impuestos se deben revisar con quien lleve la contabilidad y con el PAC antes de implementar Fiscal. No cubren todavía IEPS ni otros impuestos.

## 4. Permisos

| Permiso | ADMIN | MANAGER | WAITER | KITCHEN |
|---|---|---|---|---|
| `menu.read` | Sí | Sí | Sí | Sí |
| `menu.manage` | Sí | Sí | No | No |
| `menu.set_availability` | Sí | Sí | Sí | Sí |
| `recipes.read` | Sí | Sí | No | Sí |
| `recipes.manage` | Sí | Sí | No | No |

## 5. Requisitos para otros módulos

- **Orders (24.6):** copiar nombre, precio, impuesto y modificadores a la partida; asignar `station_id` a cada unidad; rechazar productos inactivos o agotados.
- **Orders e Inventory:** al pasar a `PREPARING`, sumar la receta y los ajustes de modificadores **por ingrediente** antes de registrar el consumo. La idempotencia por unidad, ingrediente y versión (sección 8 del documento maestro) fallaría si el mismo ingrediente llegara en dos movimientos.
- **Fiscal (24.11):** usar los datos fiscales copiados en la partida, no los actuales del producto.
- **Public QR (24.13):** exponer una vista reducida del menú.

## 6. Cambios al modelo físico

- **`products`:** `uuid`, `branch_id`, `code`, `name`, `description`, `category`, `display_order`, `price`, `status`, `availability`, `modifier_ids`, `current_recipe_version`, `fiscal`, `image_url`, `version`.
- **`modifiers`:** `uuid`, `branch_id`, `code`, `name`, `min_selections`, `max_selections`, `options[]`, `status`, `version`.
- **`recipes`:** `uuid`, `branch_id`, `product_id`, `recipe_version`, `status`, `items[]`, `created_by`, `created_at`.
- **`stations`:** `uuid`, `branch_id`, `code`, `name`, `status`, `version`.
- **`product_preparation_routes`:** `branch_id`, `product_id`, `station_id`, `priority`.
- **`branches`:** `menu_version`.
- **Índices nuevos:** `unique(uuid)` y `unique(branch_id, code)` en productos, modificadores y estaciones; `unique(branch_id, product_id, recipe_version)`; `unique(branch_id, product_id, station_id)`; `(branch_id, status, category)`.
- **Códigos de error nuevos:** `CATALOG_CODE_TAKEN`, `PRODUCT_NOT_SELLABLE`, `STATION_IN_USE`.

## 7. Riesgo conocido

Una comanda creada justo mientras se desactiva una estación podría dejar una unidad en esa estación. El impacto es bajo: un gerente la cancela o la reasigna. La reasignación se define en Orders.
